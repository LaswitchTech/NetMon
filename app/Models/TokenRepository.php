<?php

namespace App\Models;

use App\Core\DatabaseInterface;

/**
 * All database queries for the api_tokens table.
 */
class TokenRepository
{
    private DatabaseInterface $db;

    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    /**
     * Find a token record by its SHA-256 hash.
     * Returns the full row including user_id, revoked_at, expires_at.
     */
    public function findByHash(string $hash): ?array
    {
        return $this->db->fetchOne(
            'SELECT * FROM api_tokens WHERE token_hash = ? LIMIT 1',
            [$hash]
        );
    }

    /**
     * Return all tokens belonging to a user (hash is never included).
     */
    public function findByUserId(int $userId): array
    {
        $rows = $this->db->fetch(
            'SELECT id, user_id, name, last_used_at, expires_at, revoked_at, created_at
             FROM api_tokens
             WHERE user_id = ?
             ORDER BY created_at DESC',
            [$userId]
        );

        return $rows;
    }

    /**
     * Insert a new token record. Stores only the hash, never the raw token.
     */
    public function create(int $userId, string $name, string $hash, ?string $expiresAt): array
    {
        $now = date('Y-m-d H:i:s');

        $this->db->execute(
            'INSERT INTO api_tokens (user_id, name, token_hash, expires_at, created_at)
             VALUES (?, ?, ?, ?, ?)',
            [$userId, $name, $hash, $expiresAt, $now]
        );

        $id = (int) $this->db->lastInsertId();

        return $this->findByIdRaw($id);
    }

    /**
     * Mark a token as revoked. Sets revoked_at to now.
     * Returns false if the token was not found or already revoked.
     */
    public function revoke(int $id): bool
    {
        $affected = $this->db->execute(
            'UPDATE api_tokens SET revoked_at = ? WHERE id = ? AND revoked_at IS NULL',
            [date('Y-m-d H:i:s'), $id]
        );

        return $affected > 0;
    }

    /**
     * Update the last_used_at timestamp. Called on every successful token auth.
     */
    public function touchLastUsed(int $id): void
    {
        $this->db->execute(
            'UPDATE api_tokens SET last_used_at = ? WHERE id = ?',
            [date('Y-m-d H:i:s'), $id]
        );
    }

    /**
     * Find a token by primary key — used internally after creation.
     */
    public function findById(int $id): ?array
    {
        $row = $this->findByIdRaw($id);

        if ($row === null) {
            return null;
        }

        // Strip hash from externally returned records
        unset($row['token_hash']);

        return $row;
    }

    // -------------------------------------------------------------------------

    private function findByIdRaw(int $id): array
    {
        return $this->db->fetchOne(
            'SELECT * FROM api_tokens WHERE id = ? LIMIT 1',
            [$id]
        );
    }
}
