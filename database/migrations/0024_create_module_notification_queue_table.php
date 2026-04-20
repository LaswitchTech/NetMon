<?php

use App\Core\Migration;

/**
 * Notifications Module — async delivery queue.
 *
 * Creates the module_notification_queue table.
 *
 * One row per pending delivery.  The worker (scripts/notify.php) processes
 * due rows by calling the appropriate channel and then either deleting the
 * row (on success/skip/exhaustion) or rescheduling it (on retryable failure).
 *
 * Retry policy (enforced by the worker, not the schema):
 *   - sent / skipped   → row deleted immediately
 *   - failed, retryable (attempts < max_attempts) → available_at pushed forward
 *   - failed, exhausted (attempts >= max_attempts) → row deleted
 *
 * CASCADE DELETE on delivery_id ensures orphan queue rows are cleaned up
 * automatically if a delivery record is purged.
 */
class CreateModuleNotificationQueueTable extends Migration
{
    public function up(): void
    {
        $this->db->pdo()->exec(
            "CREATE TABLE IF NOT EXISTS module_notification_queue (
                id           INTEGER     NOT NULL,
                delivery_id  INTEGER     NOT NULL,
                channel      VARCHAR(32) NOT NULL,
                attempts     INTEGER     NOT NULL DEFAULT 0,
                max_attempts INTEGER     NOT NULL DEFAULT 3,
                available_at VARCHAR(32) NOT NULL,
                created_at   VARCHAR(32) NOT NULL,
                PRIMARY KEY (id),
                FOREIGN KEY (delivery_id) REFERENCES module_notification_deliveries(id) ON DELETE CASCADE
            )"
        );

        // Primary worker fetch: all due items ordered by scheduled time.
        $this->db->pdo()->exec(
            'CREATE INDEX IF NOT EXISTS module_notification_queue_available_at
             ON module_notification_queue (available_at)'
        );

        // Look up queue rows for a specific delivery (e.g. to check whether an
        // item is already queued before double-enqueuing).
        $this->db->pdo()->exec(
            'CREATE INDEX IF NOT EXISTS module_notification_queue_delivery_id
             ON module_notification_queue (delivery_id)'
        );
    }

    public function down(): void
    {
        $this->db->pdo()->exec('DROP TABLE IF EXISTS module_notification_queue');
    }
}
