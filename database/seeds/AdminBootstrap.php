<?php

use App\Core\DatabaseInterface;

/**
 * Inserts the admin group and the minimal permission set.
 *
 * This seed is idempotent: it skips any record whose unique key already exists,
 * so it is safe to run more than once (e.g. in CI or after a wipe-and-migrate).
 */
class AdminBootstrap
{
    private DatabaseInterface $db;

    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    public function run(): array
    {
        $log = [];

        $log = array_merge($log, $this->seedGroups());
        $log = array_merge($log, $this->seedPermissions());
        $log = array_merge($log, $this->seedGroupPermissions());

        return $log;
    }

    // -------------------------------------------------------------------------

    private function seedGroups(): array
    {
        $log = [];
        $now = date('Y-m-d H:i:s');

        $groups = [
            ['name' => 'admin', 'description' => 'Administrators — full access'],
        ];

        foreach ($groups as $g) {
            $existing = $this->db->fetchOne(
                'SELECT id FROM groups WHERE name = ?',
                [$g['name']]
            );

            if ($existing) {
                $log[] = "  skip group: {$g['name']} (already exists)";
                continue;
            }

            $this->db->execute(
                'INSERT INTO groups (name, description, created_at, updated_at) VALUES (?, ?, ?, ?)',
                [$g['name'], $g['description'], $now, $now]
            );

            $log[] = "  seeded group: {$g['name']}";
        }

        return $log;
    }

    private function seedPermissions(): array
    {
        $log = [];

        // Minimal permission set.
        // Naming convention: resource.action  (or bare word for global capabilities)
        $permissions = [
            ['name' => 'admin',         'description' => 'Full administrative access'],
            ['name' => 'users.view',    'description' => 'View user list and profiles'],
            ['name' => 'users.create',  'description' => 'Create new users'],
            ['name' => 'users.edit',    'description' => 'Edit existing users'],
            ['name' => 'users.delete',  'description' => 'Delete users'],
            ['name' => 'api.access',    'description' => 'Use the API with a token'],
        ];

        foreach ($permissions as $p) {
            $existing = $this->db->fetchOne(
                'SELECT id FROM permissions WHERE name = ?',
                [$p['name']]
            );

            if ($existing) {
                $log[] = "  skip permission: {$p['name']} (already exists)";
                continue;
            }

            $this->db->execute(
                'INSERT INTO permissions (name, description) VALUES (?, ?)',
                [$p['name'], $p['description']]
            );

            $log[] = "  seeded permission: {$p['name']}";
        }

        return $log;
    }

    private function seedGroupPermissions(): array
    {
        $log = [];

        // Grant every permission to the admin group
        $adminGroup = $this->db->fetchOne(
            'SELECT id FROM groups WHERE name = ?',
            ['admin']
        );

        if (!$adminGroup) {
            $log[] = "  skip group_permissions: admin group not found";
            return $log;
        }

        $permissions = $this->db->fetch('SELECT id, name FROM permissions');

        foreach ($permissions as $p) {
            $existing = $this->db->fetchOne(
                'SELECT id FROM group_permissions WHERE group_id = ? AND permission_id = ?',
                [$adminGroup['id'], $p['id']]
            );

            if ($existing) {
                $log[] = "  skip grant: admin -> {$p['name']} (already exists)";
                continue;
            }

            $this->db->execute(
                'INSERT INTO group_permissions (group_id, permission_id) VALUES (?, ?)',
                [$adminGroup['id'], $p['id']]
            );

            $log[] = "  granted: admin -> {$p['name']}";
        }

        return $log;
    }
}
