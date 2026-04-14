<?php

namespace App\Models;

use App\Core\DatabaseInterface;

/**
 * Database queries for monitored services and service-level check history.
 *
 * Covers four concerns:
 *   1. Selecting enabled services as monitoring targets (with resolved addresses)
 *   2. Loading services for one device (device detail UI)
 *   3. Persisting check results to service_checks (append-only)
 *   4. Updating the current service state summary in monitored_services
 *
 * Returns raw arrays — no domain objects.
 */
class ServiceCheckRepository
{
    private DatabaseInterface $db;

    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    // -------------------------------------------------------------------------
    // Target selection (monitoring runner)
    // -------------------------------------------------------------------------

    /**
     * Return all enabled services for all active devices, with resolved target addresses.
     *
     * Excluded:
     *   - Services where monitoring_enabled = 0
     *   - Services on soft-deleted devices (deleted_at IS NOT NULL)
     *   - Services on devices with status = 'disabled'
     *
     * Target address resolution uses the same management-interface preference
     * as device-level checks:
     *   1. Primary address (is_primary = 1) on management interface (is_management = 1)
     *   2. Fallback to devices.host if no interface/address record exists
     *
     * Services where target_address resolves to NULL are included but callers
     * are expected to skip and log a warning for each such service.
     *
     * @return array<int, array{
     *   service_id:     int,
     *   service_name:   string,
     *   protocol:       string,
     *   port:           int,
     *   device_id:      int,
     *   device_name:    string,
     *   target_address: string|null
     * }>
     */
    public function findServiceTargets(): array
    {
        return $this->db->fetch(
            "SELECT
                ms.id          AS service_id,
                ms.name        AS service_name,
                ms.protocol,
                ms.port,
                d.id           AS device_id,
                d.name         AS device_name,
                COALESCE(
                    (
                        SELECT  da.address
                        FROM    device_interfaces di
                        JOIN    device_addresses  da ON da.interface_id = di.id
                        WHERE   di.device_id    = d.id
                          AND   di.is_management = 1
                          AND   da.is_primary    = 1
                        LIMIT 1
                    ),
                    NULLIF(TRIM(d.host), '')
                ) AS target_address
            FROM   monitored_services ms
            JOIN   devices d ON d.id = ms.device_id
            WHERE  ms.monitoring_enabled = 1
              AND  d.deleted_at IS NULL
              AND  d.status != 'disabled'
            ORDER  BY d.name ASC, ms.name ASC"
        );
    }

    // -------------------------------------------------------------------------
    // UI read
    // -------------------------------------------------------------------------

    /**
     * Return all services configured for one device, ordered by name.
     *
     * Returns both enabled and disabled services so the device detail page
     * can display the full service inventory with their current state.
     *
     * @return array<int, array{
     *   id:                 int,
     *   name:               string,
     *   protocol:           string,
     *   port:               int,
     *   monitoring_enabled: int,
     *   expected_state:     string,
     *   last_state:         string|null,
     *   last_check_at:      string|null
     * }>
     */
    public function findByDevice(int $deviceId): array
    {
        return $this->db->fetch(
            "SELECT id, name, protocol, port, monitoring_enabled,
                    expected_state, last_state, last_check_at
             FROM   monitored_services
             WHERE  device_id = ?
             ORDER  BY name ASC",
            [$deviceId]
        );
    }

    /**
     * Return check history for one service, oldest first, for graph rendering.
     *
     * Returns only the fields needed for visualization. Ordered ASC so that
     * graph libraries receive points in chronological order.
     *
     * @param  int $serviceId  Service to query
     * @param  int $limit      Maximum number of rows (default 100)
     * @return array<int, array{
     *   checked_at: string,
     *   status: string,
     *   latency_ms: int|null
     * }>
     */
    public function findHistoryByService(int $serviceId, int $limit = 100): array
    {
        return $this->db->fetch(
            "SELECT checked_at, status, latency_ms
             FROM   service_checks
             WHERE  service_id = ?
             ORDER  BY checked_at ASC
             LIMIT  ?",
            [$serviceId, $limit]
        );
    }

    // -------------------------------------------------------------------------
    // Check persistence (append-only)
    // -------------------------------------------------------------------------

    /**
     * Save one service check result row to service_checks.
     *
     * This table is append-only — rows are never updated after insertion.
     * It is the source of truth for service uptime graphs, latency trends,
     * and historical analysis.
     *
     * @param  array{
     *   service_id: int,
     *   checked_at: string,
     *   status:     string,
     *   latency_ms: int|null,
     *   message:    string|null
     * } $check
     * @return int  The new service_checks row ID
     */
    public function saveCheck(array $check): int
    {
        $now = date('Y-m-d H:i:s');

        $this->db->execute(
            "INSERT INTO service_checks (service_id, checked_at, status, latency_ms, message, created_at)
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                $check['service_id'],
                $check['checked_at'],
                $check['status'],
                $check['latency_ms'],
                $check['message'],
                $now,
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    // -------------------------------------------------------------------------
    // Current state update
    // -------------------------------------------------------------------------

    /**
     * Update the cached state summary on a monitored_services row.
     *
     * monitored_services.last_state and last_check_at are summary columns —
     * they cache the most recent check result so UI queries do not need to
     * join service_checks on every page load.
     *
     * Only called when status is not 'error': if the check runner itself
     * failed (e.g. no target address), the last known state is preserved.
     *
     * @param int    $serviceId   The monitored_services row to update
     * @param string $state       'up', 'down' (not 'error' — see above)
     * @param string $checkedAt   ISO datetime matching the service_checks row
     */
    public function updateServiceState(int $serviceId, string $state, string $checkedAt): void
    {
        $this->db->execute(
            "UPDATE monitored_services SET last_state = ?, last_check_at = ? WHERE id = ?",
            [$state, $checkedAt, $serviceId]
        );
    }
}
