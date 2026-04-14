<?php

use App\Core\Migration;

/**
 * Phase 5 — Device-level monitoring history.
 *
 * Creates the device_checks table.
 *
 * Each row represents one reachability check result for a device.
 * This is an append-only historical log — device_checks rows are never
 * updated after insertion. The current device status is stored (and kept
 * up to date) in devices.status, which is a summary derived from the
 * most recent check.
 *
 * Relationship:
 *   devices (1) ──< (many) device_checks
 *
 * Note on retention: this table will grow with every monitoring cycle.
 * A retention policy (e.g. purge rows older than 30 days per device)
 * should be added once the monitoring runner is running continuously.
 * The schema supports any retention strategy without modification.
 *
 * This is a device-level check table only. Service-level checks
 * (ports, HTTP, etc.) will be stored in a separate service_checks table
 * once monitored_services is implemented.
 */
class CreateDeviceChecksTable extends Migration
{
    public function up(): void
    {
        $this->db->pdo()->exec(
            "CREATE TABLE IF NOT EXISTS device_checks (
                id         INTEGER      NOT NULL,
                device_id  INTEGER      NOT NULL,
                checked_at VARCHAR(32)  NOT NULL,
                status     VARCHAR(16)  NOT NULL,
                latency_ms INTEGER,
                message    VARCHAR(255),
                created_at VARCHAR(32)  NOT NULL,
                PRIMARY KEY (id),
                FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
            )"
        );

        // Fetch all check history for a given device (e.g. for a status graph).
        $this->db->pdo()->exec(
            'CREATE INDEX IF NOT EXISTS device_checks_device_id
             ON device_checks (device_id)'
        );

        // Time-range queries across all devices (e.g. "all checks in the last hour").
        $this->db->pdo()->exec(
            'CREATE INDEX IF NOT EXISTS device_checks_checked_at
             ON device_checks (checked_at)'
        );

        // Per-device time-range queries — most common pattern for history graphs.
        $this->db->pdo()->exec(
            'CREATE INDEX IF NOT EXISTS device_checks_device_id_checked_at
             ON device_checks (device_id, checked_at)'
        );
    }

    public function down(): void
    {
        $this->db->pdo()->exec('DROP TABLE IF EXISTS device_checks');
    }
}
