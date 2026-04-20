<?php

namespace App\Models;

use App\Core\DatabaseInterface;

/**
 * All database queries for the groups table.
 * Returns raw arrays; no domain objects.
 */
class GroupRepository
{
    private DatabaseInterface $db;

    /**
     * Groups that cannot be deleted.
     * The 'admin' group is seeded at install time and gates all /admin routes;
     * removing it would lock every user out of the admin area.
     */
    public const SYSTEM_GROUPS = ['admin'];

    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    /**
     * Return all groups with aggregate counts.
     *
     * Each row includes:
     *   id, name, description, created_at, updated_at,
     *   member_count (int), permission_count (int)
     *
     * @return array<int, array>
     */
    public function findAll(): array
    {
        return $this->db->fetch(
            'SELECT
                g.id,
                g.name,
                g.description,
                g.created_at,
                g.updated_at,
                (SELECT COUNT(*) FROM user_groups ug WHERE ug.group_id = g.id) AS member_count,
                (SELECT COUNT(*) FROM group_permissions gp WHERE gp.group_id = g.id) AS permission_count
             FROM groups g
             ORDER BY g.name ASC',
            []
        );
    }

    /**
     * Find a single group by its primary key.
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM groups WHERE id = ? LIMIT 1',
            [$id]
        );
    }

    /**
     * Return all users who are members of this group.
     *
     * @return array<int, array{id: int, display_name: string, username: string, email: string, is_active: int}>
     */
    public function findMembers(int $groupId): array
    {
        return $this->db->fetch(
            'SELECT u.id, u.display_name, u.username, u.email, u.is_active
             FROM users u
             JOIN user_groups ug ON ug.user_id = u.id
             WHERE ug.group_id = ?
             ORDER BY u.display_name ASC',
            [$groupId]
        );
    }

    /**
     * Return all permissions granted to this group.
     *
     * @return array<int, array{id: int, name: string, description: string}>
     */
    public function findPermissions(int $groupId): array
    {
        return $this->db->fetch(
            'SELECT p.id, p.name, p.description
             FROM permissions p
             JOIN group_permissions gp ON gp.permission_id = p.id
             WHERE gp.group_id = ?
             ORDER BY p.name ASC',
            [$groupId]
        );
    }

    /**
     * Check whether a group name is already in use.
     *
     * @param int|null $excludeId  When editing, exclude this group's own ID from the check.
     */
    public function isNameTaken(string $name, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            $row = $this->db->fetchOne(
                'SELECT id FROM groups WHERE name = ? AND id != ? LIMIT 1',
                [$name, $excludeId]
            );
        } else {
            $row = $this->db->fetchOne(
                'SELECT id FROM groups WHERE name = ? LIMIT 1',
                [$name]
            );
        }
        return $row !== null;
    }

    /**
     * Return true if this group name is a system-reserved group that cannot be deleted.
     */
    public function isSystemGroup(string $name): bool
    {
        return in_array(strtolower($name), self::SYSTEM_GROUPS, true);
    }

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    /**
     * Insert a new group and return the new row ID.
     *
     * Expected keys in $data:
     *   name         string   Unique group identifier (required)
     *   description  string   Human-readable description (optional)
     */
    public function create(array $data): int
    {
        $now = date('Y-m-d H:i:s');

        $this->db->execute(
            'INSERT INTO groups (name, description, created_at, updated_at) VALUES (?, ?, ?, ?)',
            [
                $data['name'],
                $data['description'] !== '' ? $data['description'] : null,
                $now,
                $now,
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * Update name and/or description of an existing group.
     *
     * Expected keys in $data:
     *   name         string   New unique name (required)
     *   description  string   New description (optional)
     */
    public function update(int $id, array $data): void
    {
        $this->db->execute(
            'UPDATE groups SET name = ?, description = ?, updated_at = ? WHERE id = ?',
            [
                $data['name'],
                $data['description'] !== '' ? $data['description'] : null,
                date('Y-m-d H:i:s'),
                $id,
            ]
        );
    }

    /**
     * Replace the full permission set for a group in a single transaction.
     *
     * All existing group_permissions rows for $groupId are deleted, then a new
     * row is inserted for each ID in $permissionIds.  An empty $permissionIds
     * array removes all permissions (valid — a group may have no permissions).
     *
     * The operation runs inside a PDO transaction so the permission set is never
     * left in a partial state if an insert fails.
     *
     * Callers are responsible for ensuring all IDs in $permissionIds exist in the
     * permissions table before calling this method.
     *
     * @param int[]  $permissionIds  Validated permission IDs to assign.
     */
    public function syncPermissions(int $groupId, array $permissionIds): void
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();

        try {
            $pdo->prepare('DELETE FROM group_permissions WHERE group_id = ?')
                ->execute([$groupId]);

            if (!empty($permissionIds)) {
                $insert = $pdo->prepare(
                    'INSERT INTO group_permissions (group_id, permission_id) VALUES (?, ?)'
                );
                foreach ($permissionIds as $pid) {
                    $insert->execute([$groupId, (int) $pid]);
                }
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    /**
     * Delete a group by ID.
     *
     * Because foreign_keys=ON is active, the database will CASCADE DELETE all
     * associated user_groups and group_permissions rows automatically.
     *
     * IMPORTANT: Callers must check isSystemGroup() before calling this method.
     * System groups (e.g. 'admin') must never be deleted through this path.
     */
    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM groups WHERE id = ?', [$id]);
    }
}
