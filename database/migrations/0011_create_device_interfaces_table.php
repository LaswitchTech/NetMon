<?php

use App\Core\Migration;

/**
 * Phase 2 — Step 1 of 3.
 *
 * Creates the device_interfaces table.
 *
 * A device interface represents one named network interface on a device
 * (e.g. eth0, WAN, Management). It groups device_addresses and carries
 * physical-layer metadata such as the MAC address.
 *
 * Relationship:
 *   devices (1) ──< (many) device_interfaces
 */
class CreateDeviceInterfacesTable extends Migration
{
    public function up(): void
    {
        $this->db->pdo()->exec(
            "CREATE TABLE IF NOT EXISTS device_interfaces (
                id            INTEGER      NOT NULL,
                device_id     INTEGER      NOT NULL,
                name          VARCHAR(64)  NOT NULL,
                mac_address   VARCHAR(17),
                is_management INTEGER      NOT NULL DEFAULT 0,
                description   VARCHAR(255),
                created_at    VARCHAR(32)  NOT NULL,
                PRIMARY KEY (id),
                FOREIGN KEY (device_id) REFERENCES devices(id) ON DELETE CASCADE
            )"
        );

        // Primary lookup: all interfaces for a given device.
        $this->db->pdo()->exec(
            'CREATE INDEX IF NOT EXISTS device_interfaces_device_id
             ON device_interfaces (device_id)'
        );

        // Quick lookup of the management interface for a device.
        $this->db->pdo()->exec(
            'CREATE INDEX IF NOT EXISTS device_interfaces_management
             ON device_interfaces (device_id, is_management)'
        );
    }

    public function down(): void
    {
        // device_addresses must be dropped first (foreign key dependency),
        // but its own migration (0012) handles that during rollback.
        // By the time this rollback runs, 0012 has already dropped device_addresses.
        $this->db->pdo()->exec('DROP TABLE IF EXISTS device_interfaces');
    }
}
