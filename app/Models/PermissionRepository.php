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
     *   id, name, description, created_at, updated_at, group_count (int)
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

    /**
     * Check whether a permission code (name) is already taken.
     *
     * @param string   $name      The code to test.
     * @param int|null $excludeId Exclude this permission ID (for edit forms).
     */
    public function isCodeTaken(string $name, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            $row = $this->db->fetchOne(
                'SELECT id FROM permissions WHERE name = ? AND id != ? LIMIT 1',
                [$name, $excludeId]
            );
        } else {
            $row = $this->db->fetchOne(
                'SELECT id FROM permissions WHERE name = ? LIMIT 1',
                [$name]
            );
        }
        return $row !== null;
    }

    /**
     * Return true if this permission is currently assigned to at least one group.
     *
     * Used to block deletion of in-use permissions.
     */
    public function isInUse(int $id): bool
    {
        $row = $this->db->fetchOne(
            'SELECT 1 FROM group_permissions WHERE permission_id = ? LIMIT 1',
            [$id]
        );
        return $row !== null;
    }

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    /**
     * Insert a new permission and return the new row ID.
     *
     * Expected keys in $data:
     *   name         string   Unique code (e.g. "devices.manage")
     *   description  string   Human-readable description (optional)
     */
    public function create(array $data): int
    {
        $now = date('Y-m-d H:i:s');

        $this->db->execute(
            'INSERT INTO permissions (name, description, created_at, updated_at) VALUES (?, ?, ?, ?)',
            [
                $data['name'],
                ($data['description'] !== '') ? $data['description'] : null,
                $now,
                $now,
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * Update a permission's code and description.
     *
     * @param int   $id    Permission ID.
     * @param array $data  Keys: name, description.
     */
    public function update(int $id, array $data): void
    {
        $this->db->execute(
            'UPDATE permissions SET name = ?, description = ?, updated_at = ? WHERE id = ?',
            [
                $data['name'],
                ($data['description'] !== '') ? $data['description'] : null,
                date('Y-m-d H:i:s'),
                $id,
            ]
        );
    }

    /**
     * Delete a permission by ID.
     *
     * Callers MUST check isInUse() before calling this method.
     * The FOREIGN KEY constraint on group_permissions will enforce referential
     * integrity at the DB level as a safety net, but the controller should
     * present a clear error before reaching this point.
     */
    public function delete(int $id): void
    {
        $this->db->execute(
            'DELETE FROM permissions WHERE id = ?',
            [$id]
        );
    }
}
