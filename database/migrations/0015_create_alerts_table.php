<?php

use App\Core\Migration;

/**
 * Phase 6 — Stateful alerts.
 *
 * Creates the alerts table.
 *
 * Design rules enforced by this schema + application logic:
 *
 *   1. There must never be more than one OPEN alert for the same
 *      (device_id, service_id, alert_type) tuple.
 *      This is enforced by AlertRepository::findOpenAlert() before every
 *      insert — if an open alert already exists, it is updated instead.
 *
 *   2. Rows are never hard-deleted. Status transitions:
 *        open → acknowledged → resolved
 *        open → suppressed
 *        open → resolved  (directly, when the condition clears)
 *
 *   3. The composite index on (device_id, service_id, alert_type, status)
 *      makes the deduplication lookup fast regardless of table size.
 *
 * Relationship:
 *   devices (1) ──< (many) alerts
 */
class CreateAlertsTable extends Migration
{
    public function up(): void
    {
        // Note: service_id is intentionally left without a FOREIGN KEY constraint here
        // because monitored_services does not exist yet. The FK will be added (via a
        // new migration or table rebuild) when monitored_services is created. Until then,
        // service_id is NULL for all device-level alerts and the column is effectively unused.
        $this->db->pdo()->exec(
            "CREATE TABLE IF NOT EXISTS alerts (
                id               INTEGER      NOT NULL,
                device_id        INTEGER      NOT NULL,
                service_id       INTEGER,
                alert_type       VARCHAR(64)  NOT NULL,
                status           VARCHAR(16)  NOT NULL DEFAULT 'open',
                first_seen_at    VARCHAR(32)  NOT NULL,
                last_seen_at     VARCHAR(32)  NOT NULL,
                last_notified_at VARCHAR(32),
                occurrence_count INTEGER      NOT NULL DEFAULT 1,
                resolved_at      VARCHAR(32),
                created_at       VARCHAR(32)  NOT NULL,
                PRIMARY KEY (id),
                FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
            )"
        );

        // Lookup all alerts for a device (UI — alert list per device).
        $this->db->pdo()->exec(
            'CREATE INDEX IF NOT EXISTS alerts_device_id
             ON alerts (device_id)'
        );

        // List all open alerts across all devices (dashboard, global alert view).
        $this->db->pdo()->exec(
            'CREATE INDEX IF NOT EXISTS alerts_status
             ON alerts (status)'
        );

        // Deduplication lookup: is there already an open alert for this
        // (device, service, type) combination? This is the hot path hit on
        // every monitoring pass for every device.
        $this->db->pdo()->exec(
            'CREATE INDEX IF NOT EXISTS alerts_open_lookup
             ON alerts (device_id, service_id, alert_type, status)'
        );
    }

    public function down(): void
    {
        $this->db->pdo()->exec('DROP TABLE IF EXISTS alerts');
    }
}
