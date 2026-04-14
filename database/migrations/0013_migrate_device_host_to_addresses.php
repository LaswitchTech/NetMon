<?php

use App\Core\Migration;

/**
 * Phase 2 — Step 3 of 3. Data migration only — no DDL.
 *
 * For each active device that has a non-empty host value, creates:
 *   1. A device_interfaces row  (name='Primary', is_management=1)
 *   2. A device_addresses row   (address=devices.host, is_primary=1)
 *
 * devices.host is NOT removed. Both sources are valid during the transition
 * period. See docs/domain-model.md — Migration Path for the deprecation plan.
 *
 * Idempotency: a device that already has a 'Primary' interface is skipped.
 * Running this migration twice produces no duplicate rows.
 *
 * Reversibility: down() deletes all rows from device_addresses and
 * device_interfaces. This is safe because those tables were created empty
 * by migrations 0011 and 0012 — all content was inserted by this migration.
 */
class MigrateDeviceHostToAddresses extends Migration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        // Fetch all active, non-merged devices that have a host value.
        $devices = $this->db->fetch(
            "SELECT id, host FROM devices
             WHERE host != ''
               AND deleted_at IS NULL"
        );

        foreach ($devices as $device) {
            // Idempotency check: skip if a 'Primary' interface already exists.
            $existing = $this->db->fetchOne(
                "SELECT id FROM device_interfaces
                 WHERE device_id = ? AND name = 'Primary'
                 LIMIT 1",
                [$device['id']]
            );

            if ($existing !== null) {
                continue;
            }

            // Create the interface.
            $this->db->execute(
                'INSERT INTO device_interfaces
                    (device_id, name, mac_address, is_management, description, created_at)
                 VALUES (?, ?, ?, ?, ?, ?)',
                [
                    $device['id'],
                    'Primary',
                    null,           // MAC unknown at this stage
                    1,              // is_management = true — this is the check target
                    'Migrated from devices.host',
                    $now,
                ]
            );

            $interfaceId = (int) $this->db->lastInsertId();

            // Detect address family: colons → IPv6, otherwise treat as IPv4.
            // Hostnames (non-numeric) are stored as 'ipv4' since they typically
            // resolve to an IPv4 address. The family can be corrected manually
            // once the device is fully profiled.
            $family = strpos($device['host'], ':') !== false ? 'ipv6' : 'ipv4';

            // Create the address.
            $this->db->execute(
                'INSERT INTO device_addresses
                    (interface_id, address, family, is_primary, created_at)
                 VALUES (?, ?, ?, ?, ?)',
                [
                    $interfaceId,
                    $device['host'],
                    $family,
                    1,      // is_primary = true — the only address on this interface
                    $now,
                ]
            );
        }
    }

    public function down(): void
    {
        // Remove all migrated data. Both tables were empty before this migration ran.
        // Addresses must be deleted before interfaces (foreign key constraint).
        $this->db->execute('DELETE FROM device_addresses', []);
        $this->db->execute('DELETE FROM device_interfaces', []);
    }
}
