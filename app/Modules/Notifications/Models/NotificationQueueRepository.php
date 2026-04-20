<?php

namespace App\Modules\Notifications\Models;

use App\Core\DatabaseInterface;

/**
 * All database queries for module_notification_queue.
 *
 * The queue decouples notification dispatch from channel delivery.
 * NotificationService::dispatch() enqueues items; the worker
 * (scripts/notify.php) fetches and delivers them.
 *
 * This repository is fully decoupled from any NetMon-specific model.
 */
class NotificationQueueRepository
{
    private DatabaseInterface $db;

    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    /**
     * Add a delivery to the queue.
     *
     * @param  int    $deliveryId   ID of the module_notification_deliveries row
     * @param  string $channel      Channel name: 'in_app', 'email', etc.
     * @param  string $availableAt  Datetime when this item becomes eligible for processing
     * @param  int    $maxAttempts  Maximum delivery attempts before the item is abandoned
     * @return int  New queue item ID
     */
    public function enqueue(
        int    $deliveryId,
        string $channel,
        string $availableAt,
        int    $maxAttempts = 3
    ): int {
        $this->db->execute(
            "INSERT INTO module_notification_queue
                 (delivery_id, channel, attempts, max_attempts, available_at, created_at)
             VALUES (?, ?, 0, ?, ?, ?)",
            [
                $deliveryId,
                $channel,
                $maxAttempts,
                $availableAt,
                date('Y-m-d H:i:s'),
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * Fetch all queue items that are due for processing.
     *
     * A row is due when available_at <= $now.  Rows where attempts already
     * equals max_attempts are excluded — these should have been deleted by the
     * worker after the last failed attempt, but the guard is kept as a safety net.
     *
     * @param  string $now   Current datetime ('Y-m-d H:i:s')
     * @param  int    $limit Maximum number of rows to return
     * @return array<int, array{
     *   id:           int,
     *   delivery_id:  int,
     *   channel:      string,
     *   attempts:     int,
     *   max_attempts: int,
     *   available_at: string,
     *   created_at:   string
     * }>
     */
    public function fetchDue(string $now, int $limit = 50): array
    {
        return $this->db->fetch(
            "SELECT *
             FROM   module_notification_queue
             WHERE  available_at <= ?
               AND  attempts < max_attempts
             ORDER  BY available_at ASC
             LIMIT  ?",
            [$now, $limit]
        );
    }

    /**
     * Reschedule a failed queue item for a later retry.
     *
     * Increments attempts and sets the new available_at.
     *
     * @param int    $id          Queue row ID
     * @param int    $attempts    New attempts value (current + 1)
     * @param string $availableAt New scheduled datetime
     */
    public function reschedule(int $id, int $attempts, string $availableAt): void
    {
        $this->db->execute(
            "UPDATE module_notification_queue
             SET    attempts     = ?,
                    available_at = ?
             WHERE  id = ?",
            [$attempts, $availableAt, $id]
        );
    }

    /**
     * Delete a queue item.
     *
     * Called after a successful delivery, a skipped delivery, or after all
     * retry attempts have been exhausted.
     *
     * @param int $id Queue row ID
     */
    public function delete(int $id): void
    {
        $this->db->execute(
            "DELETE FROM module_notification_queue WHERE id = ?",
            [$id]
        );
    }
}
