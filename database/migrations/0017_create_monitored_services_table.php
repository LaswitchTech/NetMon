<?php

use App\Core\Migration;

/**
 * Phase 8 — Monitored services per device.
 *
 * Creates the monitored_services table.
 *
 * Each row defines one service to check on a device (e.g. SSH on port 22,
 * HTTPS on port 443). This table stores configuration, not results — check
 * history lives in service_checks (migration 0018).
 *
 * Summary state (last_state, last_check_at) is cached here so the device
 * detail UI can display current service state without joining service_checks.
 *
 * Phase 8 supports TCP checks only. Protocol values for future phases:
 *   - 'tcp'   (Phase 8 — implemented)
 *   - 'udp'   (future)
 *   - 'http'  (future)
 *   - 'https' (future)
 *   - 'icmp'  (future — if per-service ping is needed alongside device-level)
 *
 * Relationship:
 *   devices (1) ──< (many) monitored_services ──< (many) service_checks
 */
class CreateMonitoredServicesTable extends Migration
{
    public function up(): void
    {
        $this->db->pdo()->exec(
            "CREATE TABLE IF NOT EXISTS monitored_services (
                id                 INTEGER      NOT NULL,
                device_id          INTEGER      NOT NULL,
                name               VARCHAR(128) NOT NULL,
                protocol           VARCHAR(16)  NOT NULL DEFAULT 'tcp',
                port               INTEGER      NOT NULL,
                monitoring_enabled INTEGER      NOT NULL DEFAULT 1,
                expected_state     VARCHAR(16)  NOT NULL DEFAULT 'up',
                last_state         VARCHAR(16),
                last_check_at      VARCHAR(32),
                created_at         VARCHAR(32)  NOT NULL,
                PRIMARY KEY (id),
                FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
            )"
        );

        // Fetch all services for one device (device detail page, per-device runner).
        $this->db->pdo()->exec(
            'CREATE INDEX IF NOT EXISTS monitored_services_device_id
             ON monitored_services (device_id)'
        );

        // Select all enabled services across devices (monitoring runner target selection).
        $this->db->pdo()->exec(
            'CREATE INDEX IF NOT EXISTS monitored_services_monitoring_enabled
             ON monitored_services (monitoring_enabled)'
        );
    }

    public function down(): void
    {
        $this->db->pdo()->exec('DROP TABLE IF EXISTS monitored_services');
    }
}
