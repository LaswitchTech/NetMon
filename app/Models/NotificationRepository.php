<?php

namespace App\Models;

use App\Core\DatabaseInterface;

/**
 * Database queries for the notification_history table.
 *
 * The table is append-only — one row per dispatch attempt.
 * A failed attempt is still recorded (status='failed') so the history
 * remains complete regardless of outcome.
 *
 * Returns raw arrays — no domain objects.
 */
class NotificationRepository
{
    private DatabaseInterface $db;

    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    /**
     * Record one notification dispatch attempt.
     *
     * @param  array{
     *   alert_id:          int,
     *   channel:           string,
     *   recipient:         string,
     *   notification_type: string,
     *   status:            'sent'|'failed',
     *   message:           string|null,
     *   sent_at:           string
     * } $data
     * @return int  New notification_history row ID
     */
    public function record(array $data): int
    {
        $now = date('Y-m-d H:i:s');

        $this->db->execute(
            "INSERT INTO notification_history
                 (alert_id, channel, recipient, notification_type, status, message, sent_at, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
            [
                $data['alert_id'],
                $data['channel'],
                $data['recipient'],
                $data['notification_type'],
                $data['status'],
                $data['message'] ?? null,
                $data['sent_at'],
                $now,
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    /**
     * Return the most recent notification history rows for a given alert.
     *
     * Ordered newest-first. Useful for auditing what was sent and when.
     *
     * @param  int $alertId
     * @param  int $limit   Maximum rows to return (default 10)
     * @return array
     */
    public function findRecentByAlert(int $alertId, int $limit = 10): array
    {
        return $this->db->fetch(
            "SELECT * FROM notification_history
             WHERE  alert_id = ?
             ORDER  BY sent_at DESC
             LIMIT  ?",
            [$alertId, $limit]
        );
    }
}
