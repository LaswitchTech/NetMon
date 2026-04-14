<?php

namespace App\Models;

use App\Core\DatabaseInterface;

/**
 * Database queries for the alerts table.
 *
 * Alert deduplication rule (enforced here, not by DB constraint):
 *   There must never be more than one OPEN alert for the same
 *   (device_id, service_id, alert_type) tuple. Callers must call
 *   findOpenAlert() before createAlert() and branch accordingly.
 *
 * Alert lifecycle:
 *   open → (re-confirmed) → last_seen_at updated, occurrence_count incremented
 *   open → resolved       → status='resolved', resolved_at set
 *   open → acknowledged   → status='acknowledged' (future UI action)
 *   open → suppressed     → status='suppressed'   (future UI action)
 *
 * Returns raw arrays — no domain objects.
 */
class AlertRepository
{
    private DatabaseInterface $db;

    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    // -------------------------------------------------------------------------
    // Read (UI / reporting)
    // -------------------------------------------------------------------------

    /**
     * Return all currently open alerts, newest last_seen_at first.
     *
     * JOINs devices and monitored_services so each row includes device_name,
     * service_name, and service_port for display. LEFT JOINs so orphaned or
     * device-level-only rows (no service) are still included.
     *
     * @return array[]
     */
    public function findAllOpen(): array
    {
        return $this->db->fetch(
            "SELECT a.*, d.name AS device_name,
                    ms.name AS service_name, ms.port AS service_port
             FROM   alerts  a
             LEFT JOIN devices            d  ON d.id  = a.device_id
             LEFT JOIN monitored_services ms ON ms.id = a.service_id
             WHERE  a.status = 'open'
             ORDER BY a.last_seen_at DESC"
        );
    }

    /**
     * Return the most recent alerts regardless of status, newest first.
     *
     * @param  int $limit  Maximum rows to return (default 100).
     * @return array[]
     */
    public function findRecent(int $limit = 100): array
    {
        return $this->db->fetch(
            "SELECT a.*, d.name AS device_name,
                    ms.name AS service_name, ms.port AS service_port
             FROM   alerts  a
             LEFT JOIN devices            d  ON d.id  = a.device_id
             LEFT JOIN monitored_services ms ON ms.id = a.service_id
             ORDER BY a.last_seen_at DESC
             LIMIT ?",
            [$limit]
        );
    }

    /**
     * Return a single alert by ID with device and service context.
     *
     * Returns null if the alert does not exist.
     * Used by the alert detail page and the action controllers.
     *
     * @return array|null  Alert row with device_name, service_name, service_port; or null.
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetchOne(
            "SELECT a.*, d.name AS device_name,
                    ms.name AS service_name, ms.port AS service_port
             FROM   alerts  a
             LEFT JOIN devices            d  ON d.id  = a.device_id
             LEFT JOIN monitored_services ms ON ms.id = a.service_id
             WHERE  a.id = ?",
            [$id]
        );
    }

    /**
     * Return all alerts for a specific device, newest first.
     *
     * @param  int $deviceId
     * @return array[]
     */
    public function findByDevice(int $deviceId): array
    {
        return $this->db->fetch(
            "SELECT a.*, d.name AS device_name,
                    ms.name AS service_name, ms.port AS service_port
             FROM   alerts  a
             LEFT JOIN devices            d  ON d.id  = a.device_id
             LEFT JOIN monitored_services ms ON ms.id = a.service_id
             WHERE  a.device_id = ?
             ORDER BY a.last_seen_at DESC",
            [$deviceId]
        );
    }

    // -------------------------------------------------------------------------
    // Lookup
    // -------------------------------------------------------------------------

    /**
     * Find the single open alert matching (device_id, service_id, alert_type).
     *
     * This is the deduplication check. Call this before createAlert() to decide
     * whether to create a new alert or update an existing one.
     *
     * NULL service_id is handled explicitly — SQL NULL != NULL so we use IS NULL
     * rather than = ? to avoid false negatives on device-level alerts.
     *
     * @param  int         $deviceId
     * @param  int|null    $serviceId  NULL = device-level alert
     * @param  string      $type       e.g. 'device_offline'
     * @return array|null  Alert row, or null if no open alert exists
     */
    public function findOpenAlert(int $deviceId, ?int $serviceId, string $type): ?array
    {
        if ($serviceId === null) {
            return $this->db->fetchOne(
                "SELECT * FROM alerts
                 WHERE  device_id  = ?
                   AND  service_id IS NULL
                   AND  alert_type = ?
                   AND  status     = 'open'
                 LIMIT 1",
                [$deviceId, $type]
            );
        }

        return $this->db->fetchOne(
            "SELECT * FROM alerts
             WHERE  device_id  = ?
               AND  service_id = ?
               AND  alert_type = ?
               AND  status     = 'open'
             LIMIT 1",
            [$deviceId, $serviceId, $type]
        );
    }

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    /**
     * Create a new open alert.
     *
     * Callers MUST call findOpenAlert() first. If an open alert already exists
     * for the same (device_id, service_id, alert_type), call incrementOccurrence()
     * instead — do NOT call createAlert() again.
     *
     * @param  array{
     *   device_id:     int,
     *   service_id:    int|null,
     *   alert_type:    string,
     *   first_seen_at: string,
     *   last_seen_at:  string
     * } $data
     * @return int  New alert ID
     */
    public function createAlert(array $data): int
    {
        $now = date('Y-m-d H:i:s');

        $this->db->execute(
            "INSERT INTO alerts
                 (device_id, service_id, alert_type, status,
                  first_seen_at, last_seen_at, last_notified_at,
                  occurrence_count, resolved_at, created_at)
             VALUES (?, ?, ?, 'open', ?, ?, NULL, 1, NULL, ?)",
            [
                $data['device_id'],
                $data['service_id'] ?? null,
                $data['alert_type'],
                $data['first_seen_at'],
                $data['last_seen_at'],
                $now,
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * Update arbitrary writable fields on an alert row.
     *
     * Accepts a map of column → value pairs. Only use this for fields not
     * covered by the dedicated methods below. Example use-case: setting
     * last_notified_at when a notification is dispatched (Phase 7).
     *
     * @param int   $alertId
     * @param array $fields  Column → value pairs (e.g. ['last_notified_at' => $ts])
     */
    public function updateAlert(int $alertId, array $fields): void
    {
        if (empty($fields)) {
            return;
        }

        $setClauses = implode(', ', array_map(fn($col) => "{$col} = ?", array_keys($fields)));
        $values     = array_values($fields);
        $values[]   = $alertId;

        $this->db->execute(
            "UPDATE alerts SET {$setClauses} WHERE id = ?",
            $values
        );
    }

    /**
     * Acknowledge an open alert.
     *
     * Sets status='acknowledged'. Guarded by AND status='open' so calling
     * this on an already-acknowledged or resolved alert is safe (idempotent).
     *
     * @param int $id
     */
    public function acknowledge(int $id): void
    {
        $this->db->execute(
            "UPDATE alerts SET status = 'acknowledged' WHERE id = ? AND status = 'open'",
            [$id]
        );
    }

    /**
     * Suppress an open alert.
     *
     * Sets status='suppressed'. Guarded by AND status='open' so calling
     * this on an already-suppressed or resolved alert is safe (idempotent).
     *
     * @param int $id
     */
    public function suppress(int $id): void
    {
        $this->db->execute(
            "UPDATE alerts SET status = 'suppressed' WHERE id = ? AND status = 'open'",
            [$id]
        );
    }

    /**
     * Resolve an open alert.
     *
     * Sets status='resolved' and records when the condition cleared.
     * Guarded by AND status='open' so calling this on an already-resolved
     * alert is safe and has no effect (idempotent).
     *
     * @param int    $alertId
     * @param string $resolvedAt  ISO datetime — typically the check timestamp
     */
    public function resolveAlert(int $alertId, string $resolvedAt): void
    {
        $this->db->execute(
            "UPDATE alerts
             SET    status      = 'resolved',
                    resolved_at = ?,
                    last_seen_at = ?
             WHERE  id     = ?
               AND  status = 'open'",
            [$resolvedAt, $resolvedAt, $alertId]
        );
    }

    /**
     * Increment the occurrence counter and update last_seen_at.
     *
     * Called when a check fails and an open alert already exists — the
     * condition is still active, so we log another occurrence instead of
     * creating a duplicate alert row.
     *
     * @param int    $alertId
     * @param string $lastSeenAt  ISO datetime — typically the check timestamp
     */
    public function incrementOccurrence(int $alertId, string $lastSeenAt): void
    {
        $this->db->execute(
            "UPDATE alerts
             SET    occurrence_count = occurrence_count + 1,
                    last_seen_at     = ?
             WHERE  id = ?",
            [$lastSeenAt, $alertId]
        );
    }

    /**
     * Update last_seen_at without changing the occurrence count.
     *
     * Useful for cases where the condition is re-observed within the same
     * pass (e.g. multiple checks confirming the same failure) without
     * counting each as a separate occurrence.
     *
     * @param int    $alertId
     * @param string $lastSeenAt  ISO datetime
     */
    public function touchLastSeen(int $alertId, string $lastSeenAt): void
    {
        $this->db->execute(
            "UPDATE alerts SET last_seen_at = ? WHERE id = ?",
            [$lastSeenAt, $alertId]
        );
    }
}
