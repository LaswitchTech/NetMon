<?php

namespace App\Models;

use App\Core\DatabaseInterface;

/**
 * All database queries for the devices table.
 * Returns raw arrays; no domain objects.
 */
class DeviceRepository
{
    private DatabaseInterface $db;

    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    /**
     * Return all active (non-deleted) devices ordered alphabetically by name.
     *
     * Soft-deleted and merged devices (deleted_at IS NOT NULL) are excluded.
     *
     * Each row includes an `address` key resolved from device_interfaces and
     * device_addresses via the following preference hierarchy:
     *   1. Primary address (is_primary = 1) on the management interface (is_management = 1)
     *   2. Falls back to devices.host if no matching interface/address record exists
     *
     * The `host` column is also included for backward compatibility during the
     * transitional period. Once all code reads `address`, `host` will be removed.
     *
     * @return array<int, array{
     *   id: int,
     *   name: string,
     *   host: string,
     *   address: string,
     *   status: string,
     *   last_check_at: string|null,
     *   created_at: string
     * }>
     */
    public function findAll(): array
    {
        return $this->db->fetch(
            "SELECT
                d.id,
                d.name,
                d.host,
                COALESCE(
                    (
                        SELECT  da.address
                        FROM    device_interfaces di
                        JOIN    device_addresses  da ON da.interface_id = di.id
                        WHERE   di.device_id    = d.id
                          AND   di.is_management = 1
                          AND   da.is_primary    = 1
                        LIMIT 1
                    ),
                    d.host
                ) AS address,
                d.status,
                d.last_check_at,
                d.created_at
            FROM   devices d
            WHERE  d.deleted_at IS NULL
            ORDER  BY d.name ASC"
        );
    }

    /**
     * Find a single active (non-deleted) device by ID.
     *
     * Returns null if the device does not exist or has been soft-deleted.
     * Includes the resolved `address` (same preference hierarchy as findAll)
     * and the management interface `description` for use in edit forms.
     *
     * @return array{
     *   id: int,
     *   name: string,
     *   host: string,
     *   address: string,
     *   description: string|null,
     *   status: string,
     *   last_check_at: string|null,
     *   created_at: string
     * }|null
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetchOne(
            "SELECT
                d.id,
                d.name,
                d.host,
                COALESCE(
                    (
                        SELECT  da.address
                        FROM    device_interfaces di
                        JOIN    device_addresses  da ON da.interface_id = di.id
                        WHERE   di.device_id    = d.id
                          AND   di.is_management = 1
                          AND   da.is_primary    = 1
                        LIMIT 1
                    ),
                    d.host
                ) AS address,
                (
                    SELECT  di.description
                    FROM    device_interfaces di
                    WHERE   di.device_id    = d.id
                      AND   di.is_management = 1
                    LIMIT 1
                ) AS description,
                d.status,
                d.last_check_at,
                d.created_at
            FROM   devices d
            WHERE  d.id = ?
              AND  d.deleted_at IS NULL",
            [$id]
        );
    }

    /**
     * Return all interfaces (with their addresses) for one active device.
     *
     * Each row in the returned array represents one interface; addresses are
     * nested under the 'addresses' key as a sub-array. Returns an empty array
     * if the device has no interface records.
     *
     * Management interfaces are listed first (is_management DESC), then by
     * interface id. Within each interface, the primary address comes first.
     *
     * @return array<int, array{
     *   id: int,
     *   name: string,
     *   mac_address: string|null,
     *   is_management: int,
     *   description: string|null,
     *   addresses: array<int, array{
     *     id: int,
     *     address: string,
     *     family: string,
     *     is_primary: int
     *   }>
     * }>
     */
    public function findInterfacesWithAddresses(int $deviceId): array
    {
        $rows = $this->db->fetch(
            "SELECT
                di.id           AS iface_id,
                di.name         AS iface_name,
                di.mac_address,
                di.is_management,
                di.description  AS iface_description,
                da.id           AS addr_id,
                da.address,
                da.family,
                da.is_primary
            FROM   device_interfaces di
            LEFT   JOIN device_addresses da ON da.interface_id = di.id
            WHERE  di.device_id = ?
            ORDER  BY di.is_management DESC, di.id ASC,
                      da.is_primary    DESC, da.id  ASC",
            [$deviceId]
        );

        // Group flat JOIN rows into interfaces with nested address arrays.
        $interfaces = [];
        foreach ($rows as $row) {
            $ifaceId = (int) $row['iface_id'];
            if (!isset($interfaces[$ifaceId])) {
                $interfaces[$ifaceId] = [
                    'id'            => $ifaceId,
                    'name'          => $row['iface_name'],
                    'mac_address'   => $row['mac_address'],
                    'is_management' => (int) $row['is_management'],
                    'description'   => $row['iface_description'],
                    'addresses'     => [],
                ];
            }
            if ($row['addr_id'] !== null) {
                $interfaces[$ifaceId]['addresses'][] = [
                    'id'         => (int) $row['addr_id'],
                    'address'    => $row['address'],
                    'family'     => $row['family'],
                    'is_primary' => (int) $row['is_primary'],
                ];
            }
        }

        return array_values($interfaces);
    }

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    /**
     * Create a new device with one default management interface and one primary address.
     *
     * Transitional: also writes the address to devices.host so the fallback in
     * findAll() and findById() remains valid until devices.host is retired.
     *
     * @param  array{name: string, address: string, description?: string|null} $data
     * @return int  The new device ID
     */
    public function create(array $data): int
    {
        $now         = date('Y-m-d H:i:s');
        $address     = trim($data['address']);
        $family      = $this->detectFamily($address);
        $description = isset($data['description']) && $data['description'] !== ''
            ? $data['description']
            : null;

        // 1. Insert logical device row; devices.host kept in sync during transition.
        $this->db->execute(
            "INSERT INTO devices (name, host, status, created_at)
             VALUES (?, ?, 'unknown', ?)",
            [trim($data['name']), $address, $now]
        );
        $deviceId = (int) $this->db->lastInsertId();

        // 2. Insert default management interface.
        $this->db->execute(
            "INSERT INTO device_interfaces (device_id, name, is_management, description, created_at)
             VALUES (?, 'Primary', 1, ?, ?)",
            [$deviceId, $description, $now]
        );
        $interfaceId = (int) $this->db->lastInsertId();

        // 3. Insert primary address on that interface.
        $this->db->execute(
            "INSERT INTO device_addresses (interface_id, address, family, is_primary, created_at)
             VALUES (?, ?, ?, 1, ?)",
            [$interfaceId, $address, $family, $now]
        );

        return $deviceId;
    }

    /**
     * Update a device's name and primary address.
     *
     * Transitional: also updates devices.host to keep the fallback safe.
     *
     * If the device has an existing management interface, its primary address
     * row is updated (or inserted if missing). If no management interface
     * exists at all (defensive case), one is created along with a primary address.
     *
     * @param  int   $id
     * @param  array{name: string, address: string, description?: string|null} $data
     */
    public function update(int $id, array $data): void
    {
        $now         = date('Y-m-d H:i:s');
        $address     = trim($data['address']);
        $family      = $this->detectFamily($address);
        $description = isset($data['description']) && $data['description'] !== ''
            ? $data['description']
            : null;

        // 1. Update the logical device row; keep devices.host in sync.
        $this->db->execute(
            "UPDATE devices SET name = ?, host = ? WHERE id = ? AND deleted_at IS NULL",
            [trim($data['name']), $address, $id]
        );

        // 2. Find the existing management interface (if any).
        $iface = $this->db->fetchOne(
            "SELECT id FROM device_interfaces
             WHERE device_id = ? AND is_management = 1
             LIMIT 1",
            [$id]
        );

        if ($iface !== null) {
            $interfaceId = (int) $iface['id'];

            // Update interface description.
            $this->db->execute(
                "UPDATE device_interfaces SET description = ? WHERE id = ?",
                [$description, $interfaceId]
            );

            // Update the existing primary address, or insert one if missing.
            $addr = $this->db->fetchOne(
                "SELECT id FROM device_addresses
                 WHERE interface_id = ? AND is_primary = 1
                 LIMIT 1",
                [$interfaceId]
            );

            if ($addr !== null) {
                $this->db->execute(
                    "UPDATE device_addresses SET address = ?, family = ? WHERE id = ?",
                    [$address, $family, (int) $addr['id']]
                );
            } else {
                $this->db->execute(
                    "INSERT INTO device_addresses (interface_id, address, family, is_primary, created_at)
                     VALUES (?, ?, ?, 1, ?)",
                    [$interfaceId, $address, $family, $now]
                );
            }
        } else {
            // Defensive: no management interface exists — create one.
            $this->db->execute(
                "INSERT INTO device_interfaces (device_id, name, is_management, description, created_at)
                 VALUES (?, 'Primary', 1, ?, ?)",
                [$id, $description, $now]
            );
            $interfaceId = (int) $this->db->lastInsertId();

            $this->db->execute(
                "INSERT INTO device_addresses (interface_id, address, family, is_primary, created_at)
                 VALUES (?, ?, ?, 1, ?)",
                [$interfaceId, $address, $family, $now]
            );
        }
    }

    /**
     * Soft-delete a device by setting deleted_at.
     *
     * Does not modify device_interfaces or device_addresses — those records
     * are preserved for history and potential future merge workflows.
     * The device will no longer appear in findAll() or findById() queries.
     */
    public function softDelete(int $id): void
    {
        $this->db->execute(
            "UPDATE devices SET deleted_at = ? WHERE id = ? AND deleted_at IS NULL",
            [date('Y-m-d H:i:s'), $id]
        );
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Detect the address family from a raw value.
     *
     * Returns 'ipv6' if the value contains ':', 'ipv4' otherwise.
     * Hostnames (no ':') are stored as 'ipv4' as a pragmatic default
     * during the transitional phase, consistent with migration 0013.
     */
    private function detectFamily(string $address): string
    {
        return str_contains($address, ':') ? 'ipv6' : 'ipv4';
    }
}
