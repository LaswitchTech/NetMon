<?php

namespace App\Core;

/**
 * Centralized permission checker.
 *
 * All authorization decisions flow through here so the query logic
 * lives in exactly one place regardless of whether the caller is
 * session-based or token-based.
 */
class Gate
{
    private DatabaseInterface $db;

    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    /**
     * Return all permission names granted to a user via their groups.
     * Result is an indexed array of strings, e.g. ['admin', 'users.view'].
     */
    public function permissionsForUser(int $userId): array
    {
        $rows = $this->db->fetch(
            'SELECT p.name
             FROM permissions p
             JOIN group_permissions gp ON gp.permission_id = p.id
             JOIN user_groups      ug  ON ug.group_id      = gp.group_id
             WHERE ug.user_id = ?',
            [$userId]
        );

        return array_column($rows, 'name');
    }

    /**
     * Return true if the user has been granted the named permission.
     */
    public function userCan(int $userId, string $permission): bool
    {
        return in_array($permission, $this->permissionsForUser($userId), true);
    }

    /**
     * Return true if the principal (built by auth middleware) has the permission.
     *
     * Accepts the 'principal' array written to the container by SessionAuth
     * or TokenAuth.  This keeps permission checks uniform regardless of how
     * the caller was authenticated.
     */
    public function can(array $principal, string $permission): bool
    {
        return in_array($permission, $principal['permissions'] ?? [], true);
    }
}
