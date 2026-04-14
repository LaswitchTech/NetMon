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
}
