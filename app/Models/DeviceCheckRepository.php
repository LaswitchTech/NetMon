<?php

namespace App\Models;

use App\Core\DatabaseInterface;

/**
 * Database queries for device-level monitoring checks.
 *
 * Covers three concerns:
 *   1. Selecting active devices as monitoring targets (resolves target addresses)
 *   2. Persisting check results to device_checks (historical, append-only)
 *   3. Updating the current device status summary in devices.status / last_check_at
 *
 * Returns raw arrays — no domain objects.
 */
class DeviceCheckRepository
{
    private DatabaseInterface $db;

    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    // -------------------------------------------------------------------------
    // Target selection
    // -------------------------------------------------------------------------

    /**
     * Return all active devices that should be monitored this cycle.
     *
     * Excluded:
     *   - Soft-deleted devices (deleted_at IS NOT NULL)
     *   - Devices with status = 'disabled' (future use — no rows yet)
     *
     * Target address resolution:
     *   1. Primary address (is_primary = 1) on the management interface (is_management = 1)
     *   2. Falls back to devices.host if no matching interface/address record exists
     *
     * Devices where the resolved target_address is empty (NULL or blank) are
     * included in the result but callers are expected to skip them — the runner
     * logs a warning for each such device.
     *
     * @return array<int, array{
     *   id: int,
     *   name: string,
     *   target_address: string|null,
     *   current_status: string
     * }>
     */
    public function findMonitoringTargets(): array
    {
        return $this->db->fetch(
            "SELECT
                d.id,
                d.name,
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
                ) AS target_address,
                d.status AS current_status
            FROM   devices d
            WHERE  d.deleted_at IS NULL
              AND  d.status != 'disabled'
            ORDER  BY d.name ASC"
        );
    }

    // -------------------------------------------------------------------------
    // History read (UI)
    // -------------------------------------------------------------------------

    /**
     * Return the most recent check rows for one device, newest first.
     *
     * Used by the device detail page to display monitoring history.
     * The result is ordered by checked_at DESC so callers can render the
     * table without additional sorting.
     *
     * @param  int $deviceId  Device to query
     * @param  int $limit     Maximum number of rows to return (default 50)
     * @return array<int, array{
     *   id: int,
     *   checked_at: string,
     *   status: string,
     *   latency_ms: int|null,
     *   message: string|null
     * }>
     */
    public function findRecentByDevice(int $deviceId, int $limit = 50): array
    {
        return $this->db->fetch(
            "SELECT id, checked_at, status, latency_ms, message
             FROM   device_checks
             WHERE  device_id = ?
             ORDER  BY checked_at DESC
             LIMIT  ?",
            [$deviceId, $limit]
        );
    }

    /**
     * Return check history for one device, oldest first, for graph rendering.
     *
     * Returns only the fields needed for visualization (no message). Ordered
     * ASC so that graph libraries receive points in chronological order without
     * needing to reverse the array.
     *
     * @param  int $deviceId  Device to query
     * @param  int $limit     Maximum number of rows (default 100)
     * @return array<int, array{
     *   checked_at: string,
     *   status: string,
     *   latency_ms: int|null
     * }>
     */
    public function findHistoryByDevice(int $deviceId, int $limit = 100): array
    {
        return $this->db->fetch(
            "SELECT checked_at, status, latency_ms
             FROM   device_checks
             WHERE  device_id = ?
             ORDER  BY checked_at ASC
             LIMIT  ?",
            [$deviceId, $limit]
        );
    }

    // -------------------------------------------------------------------------
    // Check persistence (append-only)
    // -------------------------------------------------------------------------

    /**
     * Save one check result row to device_checks.
     *
     * This table is append-only — rows are never updated. It is the source of
     * truth for uptime graphs, latency trends, and historical analysis.
     *
     * @param  array{
     *   device_id:  int,
     *   checked_at: string,
     *   status:     string,
     *   latency_ms: int|null,
     *   message:    string|null
     * } $check
     * @return int  The new device_checks row ID
     */
    public function saveCheck(array $check): int
    {
        $now = date('Y-m-d H:i:s');

        $this->db->execute(
            "INSERT INTO device_checks (device_id, checked_at, status, latency_ms, message, created_at)
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                $check['device_id'],
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
    // Current status update
    // -------------------------------------------------------------------------

    /**
     * Update the current aggregate status and last_check_at on the devices row.
     *
     * devices.status and devices.last_check_at are summary columns — they hold
     * the result of the most recent check so UI queries can read them without
     * joining against the full device_checks history.
     *
     * This is the only method that writes devices.status after the initial
     * device creation (where it defaults to 'unknown').
     *
     * @param int    $deviceId   Device to update
     * @param string $status     Canonical device status: 'online', 'offline', 'unknown'
     * @param string $checkedAt  ISO datetime of the check (same value stored in device_checks)
     */
    public function updateDeviceStatus(int $deviceId, string $status, string $checkedAt): void
    {
        $this->db->execute(
            "UPDATE devices SET status = ?, last_check_at = ? WHERE id = ?",
            [$status, $checkedAt, $deviceId]
        );
    }
}
