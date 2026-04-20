<?php

namespace App\Models;

use App\Core\DatabaseInterface;

/**
 * All database queries for the permissions table.
 * Returns raw arrays; no domain objects.
 */
class PermissionRepository
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
     * Return all permissions with a count of groups that hold each permission.
     *
     * Each row includes:
     *   id, name, description, created_at, updated_at,
     *   group_count (int)
     *
     * @return array<int, array>
     */
    public function findAll(): array
    {
        return $this->db->fetch(
            'SELECT
                p.id,
                p.name,
                p.description,
                p.created_at,
                p.updated_at,
                (SELECT COUNT(*) FROM group_permissions gp WHERE gp.permission_id = p.id) AS group_count
             FROM permissions p
             ORDER BY p.name ASC',
            []
        );
    }

    /**
     * Find a single permission by its primary key.
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM permissions WHERE id = ? LIMIT 1',
            [$id]
        );
    }
}
