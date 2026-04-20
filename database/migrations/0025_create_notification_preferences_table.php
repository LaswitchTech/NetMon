<?php

use App\Core\Migration;

/**
 * Notification preferences — per-user channel enable/disable flags.
 *
 * One row per user per channel.  A missing row means "enabled" (default).
 * INSERT OR REPLACE is used for upserts so the composite PK handles
 * uniqueness without a separate constraint.
 *
 * Channels in scope for Phase 1: 'in_app', 'email'
 * Channel 'sms' is deferred.
 *
 * The worker (scripts/notify.php) is responsible for checking these
 * preferences before delivering queue items in a future phase.
 * Phase 1 stores and exposes preferences in the UI but the delivery
 * path does not yet filter on them.
 */
class CreateNotificationPreferencesTable extends Migration
{
    public function up(): void
    {
        $this->db->pdo()->exec(
            "CREATE TABLE IF NOT EXISTS notification_preferences (
                user_id    INTEGER     NOT NULL,
                channel    VARCHAR(32) NOT NULL,
                enabled    INTEGER     NOT NULL DEFAULT 1,
                updated_at VARCHAR(32) NOT NULL,
                PRIMARY KEY (user_id, channel),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
            )"
        );
    }

    public function down(): void
    {
        $this->db->pdo()->exec('DROP TABLE IF EXISTS notification_preferences');
    }
}
