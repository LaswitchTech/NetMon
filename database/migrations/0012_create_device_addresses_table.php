<?php

use App\Core\Migration;

/**
 * Phase 2 — Step 2 of 3.
 *
 * Creates the device_addresses table.
 *
 * A device address is one IP address assigned to a specific network interface.
 * A single interface may carry multiple addresses (e.g. dual-stack IPv4+IPv6,
 * or multiple IPv4 addresses on a load-balanced host).
 *
 * Relationship:
 *   device_interfaces (1) ──< (many) device_addresses
 *
 * The address column uses VARCHAR(45) to accommodate the longest possible
 * IPv6 address with prefix notation (e.g. 2001:db8::/32 = 32 chars max,
 * but full expanded with prefix fits in 45).
 *
 * family values: 'ipv4' | 'ipv6'
 */
class CreateDeviceAddressesTable extends Migration
{
    public function up(): void
    {
        $this->db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS device_addresses (
                id           INTEGER     NOT NULL,
                interface_id INTEGER     NOT NULL,
                address      VARCHAR(45) NOT NULL,
                family       VARCHAR(4)  NOT NULL,
                is_primary   INTEGER     NOT NULL DEFAULT 0,
                created_at   VARCHAR(32) NOT NULL,
                PRIMARY KEY (id),
                FOREIGN KEY (interface_id) REFERENCES device_interfaces(id) ON DELETE CASCADE
            )'
        );

        // Primary lookup: all addresses for a given interface.
        $this->db->pdo()->exec(
            'CREATE INDEX IF NOT EXISTS device_addresses_interface_id
             ON device_addresses (interface_id)'
        );

        // Discovery matching: find which device already claims a given IP.
        // Used by the discovery runner to auto-match findings to known devices.
        $this->db->pdo()->exec(
            'CREATE INDEX IF NOT EXISTS device_addresses_address
             ON device_addresses (address)'
        );
    }

    public function down(): void
    {
        $this->db->pdo()->exec('DROP TABLE IF EXISTS device_addresses');
    }
}
