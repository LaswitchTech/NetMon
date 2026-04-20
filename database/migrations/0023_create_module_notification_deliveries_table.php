<?php

use App\Core\Migration;

/**
 * Notifications Module — Step 2 of 2.
 *
 * Creates the module_notification_deliveries table.
 *
 * One row per user per channel per notification event.
 * Tracks delivery status and (for in_app channel) read state.
 *
 * Lifecycle:
 *   pending  → delivery record created; channel has not yet been invoked
 *   sent     → channel delivered successfully
 *   failed   → channel delivery failed; error column holds details
 *   skipped  → channel decided not to deliver (e.g. user opted out in future)
 *
 * read_at:
 *   NULL   → notification is unread (in_app channel only)
 *   set    → notification was read/dismissed at this timestamp
 *   For non-in_app channels this column is always NULL.
 *
 * CASCADE DELETE ensures delivery records are removed when a notification
 * event or a user account is deleted.  Notes are non-destructive; deliveries
 * are derived data and can be pruned with their parent.
 */
class CreateModuleNotificationDeliveriesTable extends Migration
{
    public function up(): void
    {
        $this->db->pdo()->exec(
            "CREATE TABLE IF NOT EXISTS module_notification_deliveries (
                id              INTEGER     NOT NULL,
                notification_id INTEGER     NOT NULL,
                user_id         INTEGER     NOT NULL,
                channel         VARCHAR(32) NOT NULL,
                status          VARCHAR(16) NOT NULL DEFAULT 'pending',
                read_at         VARCHAR(32),
                sent_at         VARCHAR(32),
                error           VARCHAR(255),
                created_at      VARCHAR(32) NOT NULL,
                PRIMARY KEY (id),
                FOREIGN KEY (notification_id) REFERENCES module_notifications(id)  ON DELETE CASCADE,
                FOREIGN KEY (user_id)         REFERENCES users(id)                 ON DELETE CASCADE
            )"
        );

        // Unread in_app inbox count for a user — this is a hot path hit on every
        // page load once the topbar badge is wired up.
        $this->db->pdo()->exec(
            'CREATE INDEX IF NOT EXISTS module_notification_deliveries_user_unread
             ON module_notification_deliveries (user_id, channel, read_at)'
        );

        // All deliveries for a single notification event.
        $this->db->pdo()->exec(
            'CREATE INDEX IF NOT EXISTS module_notification_deliveries_notification_id
             ON module_notification_deliveries (notification_id)'
        );
    }

    public function down(): void
    {
        $this->db->pdo()->exec('DROP TABLE IF EXISTS module_notification_deliveries');
    }
}
