<?php

use App\Core\Migration;

/**
 * Notifications Module — Step 1 of 2.
 *
 * Creates the module_notifications table.
 *
 * One row per notification event.  Stores the content and source context.
 * The table is prefixed `module_` to avoid collision with the existing
 * app-level `notification_history` table used by the alert dispatch system.
 *
 * source_type + source_id are a polymorphic reference to the entity that
 * generated the notification (e.g. alert, device, system).  Not a FK —
 * the source entity may be deleted while the notification is retained.
 *
 * The data column holds a JSON-encoded payload for channel-specific
 * rendering (e.g. a link to the alert, device ID, severity).  NULL means
 * no additional context.
 */
class CreateModuleNotificationsTable extends Migration
{
    public function up(): void
    {
        $this->db->pdo()->exec(
            "CREATE TABLE IF NOT EXISTS module_notifications (
                id          INTEGER      NOT NULL,
                source_type VARCHAR(64),
                source_id   INTEGER,
                title       VARCHAR(255) NOT NULL,
                body        TEXT         NOT NULL,
                data        TEXT,
                created_at  VARCHAR(32)  NOT NULL,
                PRIMARY KEY (id)
            )"
        );

        // Look up all notifications that originated from a specific entity.
        $this->db->pdo()->exec(
            'CREATE INDEX IF NOT EXISTS module_notifications_source
             ON module_notifications (source_type, source_id)'
        );

        // Chronological retrieval — newest notification events first.
        $this->db->pdo()->exec(
            'CREATE INDEX IF NOT EXISTS module_notifications_created_at
             ON module_notifications (created_at)'
        );
    }

    public function down(): void
    {
        $this->db->pdo()->exec('DROP TABLE IF EXISTS module_notifications');
    }
}
