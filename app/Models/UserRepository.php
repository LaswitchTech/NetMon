<?php

namespace App\Models;

use App\Core\DatabaseInterface;

/**
 * All database queries for the users table.
 * Returns raw arrays; no domain objects.
 */
class UserRepository
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
     * Find an active user by their username.
     */
    public function findByUsername(string $username): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM users WHERE username = ? AND is_active = 1 LIMIT 1',
            [$username]
        );
    }

    /**
     * Find an active user by their email address.
     */
    public function findByEmail(string $email): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM users WHERE email = ? AND is_active = 1 LIMIT 1',
            [$email]
        );
    }

    /**
     * Find an active user by their primary key.
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM users WHERE id = ? AND is_active = 1 LIMIT 1',
            [$id]
        );
    }

    /**
     * Find any user by primary key, regardless of active status.
     *
     * Used by admin interfaces that need to load inactive accounts.
     * Never includes password_hash in practice — callers should not expose it.
     */
    public function findByIdAny(int $id): ?array
    {
        return $this->db->fetchOne(
            'SELECT id, display_name, username, email, is_active, created_at, updated_at
             FROM users WHERE id = ? LIMIT 1',
            [$id]
        );
    }

    /**
     * Return all active user rows.
     *
     * Used by the monitoring runner to resolve notification recipients.
     * "All active users" is the Phase 1 recipient rule — a future preference
     * system (module_notification_preferences) will replace this with per-user
     * opt-in/opt-out per channel and event type.
     *
     * @return array<int, array{id: int, username: string, email: string, display_name: string, ...}>
     */
    public function findAllActive(): array
    {
        return $this->db->fetch(
            'SELECT * FROM users WHERE is_active = 1 ORDER BY id ASC',
            []
        );
    }

    /**
     * Return all user rows including inactive accounts.
     *
     * Used by admin interfaces. Never includes password_hash.
     *
     * @return array<int, array{id: int, display_name: string, username: string, email: string, is_active: int, created_at: string, updated_at: string}>
     */
    public function findAll(): array
    {
        return $this->db->fetch(
            'SELECT id, display_name, username, email, is_active, created_at, updated_at
             FROM users
             ORDER BY display_name ASC',
            []
        );
    }

    /**
     * Return all groups the user belongs to.
     *
     * @return array<int, array{id: int, name: string, description: string|null}>
     */
    public function findGroups(int $userId): array
    {
        return $this->db->fetch(
            'SELECT g.id, g.name, g.description
             FROM groups g
             JOIN user_groups ug ON ug.group_id = g.id
             WHERE ug.user_id = ?
             ORDER BY g.name ASC',
            [$userId]
        );
    }

    /**
     * Replace the full group membership set for a user in a single transaction.
     *
     * All existing user_groups rows for $userId are deleted, then a new row is
     * inserted for each ID in $groupIds.  An empty $groupIds array removes the
     * user from all groups (valid, but callers should check safety guards first).
     *
     * Callers are responsible for ensuring all IDs in $groupIds exist in the
     * groups table before calling this method.
     *
     * @param int[]  $groupIds  Validated group IDs to assign.
     */
    public function syncGroups(int $userId, array $groupIds): void
    {
        $pdo = $this->db->pdo();
        $pdo->beginTransaction();

        try {
            $pdo->prepare('DELETE FROM user_groups WHERE user_id = ?')
                ->execute([$userId]);

            if (!empty($groupIds)) {
                $insert = $pdo->prepare(
                    'INSERT INTO user_groups (user_id, group_id) VALUES (?, ?)'
                );
                foreach ($groupIds as $gid) {
                    $insert->execute([$userId, (int) $gid]);
                }
            }

            $pdo->commit();
        } catch (\Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    }

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    /**
     * Check whether a username is already taken.
     *
     * @param string   $username   The username to test.
     * @param int|null $excludeId  Exclude this user ID from the check (for edit forms).
     */
    public function isUsernameTaken(string $username, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            $row = $this->db->fetchOne(
                'SELECT id FROM users WHERE username = ? AND id != ? LIMIT 1',
                [$username, $excludeId]
            );
        } else {
            $row = $this->db->fetchOne(
                'SELECT id FROM users WHERE username = ? LIMIT 1',
                [$username]
            );
        }
        return $row !== null;
    }

    /**
     * Check whether an email address is already taken.
     *
     * @param string   $email      The email to test.
     * @param int|null $excludeId  Exclude this user ID from the check (for edit forms).
     */
    public function isEmailTaken(string $email, ?int $excludeId = null): bool
    {
        if ($excludeId !== null) {
            $row = $this->db->fetchOne(
                'SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1',
                [$email, $excludeId]
            );
        } else {
            $row = $this->db->fetchOne(
                'SELECT id FROM users WHERE email = ? LIMIT 1',
                [$email]
            );
        }
        return $row !== null;
    }

    /**
     * Insert a new user record and return the new row ID.
     *
     * Expected keys in $data:
     *   display_name   string  Full name for display
     *   username       string  Login identifier (must be unique)
     *   email          string  Email address (must be unique)
     *   password_hash  string  Pre-hashed password (use password_hash())
     *   is_active      int     1 = active, 0 = inactive (default 1)
     *
     * @throws \RuntimeException on DB constraint violation.
     */
    public function create(array $data): int
    {
        $now = date('Y-m-d H:i:s');

        $this->db->execute(
            'INSERT INTO users (display_name, username, email, password_hash, is_active, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)',
            [
                $data['display_name']  ?? '',
                $data['username'],
                $data['email'],
                $data['password_hash'],
                $data['is_active'] ?? 1,
                $now,
                $now,
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * Update a user's display name and email.
     *
     * @param int    $id   User ID.
     * @param array  $data Keys: display_name, email.
     */
    public function update(int $id, array $data): void
    {
        $this->db->execute(
            'UPDATE users SET display_name = ?, email = ?, updated_at = ? WHERE id = ?',
            [
                $data['display_name'],
                $data['email'],
                date('Y-m-d H:i:s'),
                $id,
            ]
        );
    }

    /**
     * Replace a user's password hash.
     *
     * The caller is responsible for generating the hash with password_hash().
     *
     * @param int    $id            User ID.
     * @param string $passwordHash  New bcrypt hash.
     */
    public function setPassword(int $id, string $passwordHash): void
    {
        $this->db->execute(
            'UPDATE users SET password_hash = ?, updated_at = ? WHERE id = ?',
            [$passwordHash, date('Y-m-d H:i:s'), $id]
        );
    }

    /**
     * Set the active flag for a user.
     *
     * Callers must check safety guards (e.g. last-active-admin) before calling.
     *
     * @param int  $id     User ID.
     * @param bool $active True to activate, false to deactivate.
     */
    public function setActive(int $id, bool $active): void
    {
        $this->db->execute(
            'UPDATE users SET is_active = ?, updated_at = ? WHERE id = ?',
            [$active ? 1 : 0, date('Y-m-d H:i:s'), $id]
        );
    }
}
