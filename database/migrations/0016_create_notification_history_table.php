<?php

use App\Core\Migration;

/**
 * Phase 7 — Notification history.
 *
 * Creates the notification_history table.
 *
 * One row per dispatch attempt per alert. The table is append-only — rows
 * are never updated. A failed send is recorded with status='failed' and a
 * message describing the error, so the history is complete regardless of
 * outcome.
 *
 * Relationship:
 *   alerts (1) ──< (many) notification_history
 *
 * The notification_type column captures where in the alert lifecycle this
 * notification was sent:
 *   'open'     — alert first opened; sent immediately
 *   'reminder' — alert still open; sent after throttle interval elapsed
 *   'resolved' — alert resolved; sent once (future)
 */
class CreateNotificationHistoryTable extends Migration
{
    public function up(): void
    {
        $this->db->pdo()->exec(
            "CREATE TABLE IF NOT EXISTS notification_history (
                id                INTEGER      NOT NULL,
                alert_id          INTEGER      NOT NULL,
                channel           VARCHAR(32)  NOT NULL,
                recipient         VARCHAR(255) NOT NULL,
                notification_type VARCHAR(16)  NOT NULL,
                status            VARCHAR(8)   NOT NULL,
                message           VARCHAR(255),
                sent_at           VARCHAR(32)  NOT NULL,
                created_at        VARCHAR(32)  NOT NULL,
                PRIMARY KEY (id),
                FOREIGN KEY (alert_id) REFERENCES alerts(id) ON DELETE CASCADE
            )"
        );

        // Fetch all notifications for a specific alert (audit trail).
        $this->db->pdo()->exec(
            'CREATE INDEX IF NOT EXISTS notification_history_alert_id
             ON notification_history (alert_id)'
        );

        // Time-range queries — recent dispatch activity across all alerts.
        $this->db->pdo()->exec(
            'CREATE INDEX IF NOT EXISTS notification_history_sent_at
             ON notification_history (sent_at)'
        );
    }

    public function down(): void
    {
        $this->db->pdo()->exec('DROP TABLE IF EXISTS notification_history');
    }
}
