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

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

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
}
