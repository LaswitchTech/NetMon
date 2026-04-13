<?php

namespace App\Auth;

use App\Core\Gate;
use App\Models\TokenRepository;
use App\Models\UserRepository;

/**
 * Manages the full lifecycle of API tokens.
 *
 * Token security model:
 *   - Raw tokens are generated with random_bytes(32) → 64-char hex string.
 *   - Only SHA-256(raw) is stored.  The raw token is returned once on creation
 *     and is irrecoverable thereafter.
 *   - Lookup is by hash; the unique index on token_hash ensures a single result.
 *   - Timing attacks are not a concern for hash lookup (hashes are not secrets;
 *     the 64-char entropy of the raw token is the security factor).
 */
class TokenService
{
    private TokenRepository $tokens;
    private UserRepository  $users;
    private Gate            $gate;

    public function __construct(
        TokenRepository $tokens,
        UserRepository  $users,
        Gate            $gate
    ) {
        $this->tokens = $tokens;
        $this->users  = $users;
        $this->gate   = $gate;
    }

    // -------------------------------------------------------------------------
    // Token creation
    // -------------------------------------------------------------------------

    /**
     * Generate a new API token for a user.
     *
     * Returns:
     *   'raw'    — the plain-text token shown to the user ONCE; never stored
     *   'record' — the DB record (no hash field)
     *
     * @param  int         $userId
     * @param  string      $name      Human-readable label
     * @param  string|null $expiresAt ISO datetime string, or null for no expiry
     * @return array{raw: string, record: array}
     */
    public function generate(int $userId, string $name, ?string $expiresAt = null): array
    {
        $raw  = bin2hex(random_bytes(32));   // 64-char hex, 256 bits of entropy
        $hash = hash('sha256', $raw);

        $record = $this->tokens->create($userId, $name, $hash, $expiresAt);

        // Strip hash before returning — callers should never see it
        unset($record['token_hash']);

        return ['raw' => $raw, 'record' => $record];
    }

    // -------------------------------------------------------------------------
    // Token verification
    // -------------------------------------------------------------------------

    /**
     * Verify a raw token from an Authorization header and return a principal.
     *
     * A principal is the resolved identity context used throughout the
     * middleware and authorization layer:
     *   [
     *     'user'        => [...safe user fields...],
     *     'auth_method' => 'token',
     *     'token'       => [...token record without hash...],
     *     'permissions' => ['admin', 'users.view', ...],
     *   ]
     *
     * Returns null when the token is invalid, revoked, expired, or its user
     * is inactive.  Updates last_used_at as a side effect on success.
     */
    public function verify(string $rawToken): ?array
    {
        if ($rawToken === '') {
            return null;
        }

        $hash   = hash('sha256', $rawToken);
        $record = $this->tokens->findByHash($hash);

        if ($record === null) {
            return null;
        }

        // Revocation check
        if ($record['revoked_at'] !== null) {
            return null;
        }

        // Expiry check
        if ($record['expires_at'] !== null && strtotime($record['expires_at']) <= time()) {
            return null;
        }

        // User must still be active
        $user = $this->users->findById((int) $record['user_id']);

        if ($user === null) {
            return null;
        }

        // Update last_used_at — best-effort, do not fail auth if this errors
        try {
            $this->tokens->touchLastUsed((int) $record['id']);
        } catch (\Throwable) {
            // Non-fatal: authentication still succeeds
        }

        // Resolve effective permissions.
        // Token inherits the full user permission set.
        // When token_permissions is added, intersect here instead.
        $permissions = $this->gate->permissionsForUser((int) $user['id']);

        // Strip hash before building principal
        unset($record['token_hash']);

        return [
            'user'        => $this->safeUser($user),
            'auth_method' => 'token',
            'token'       => $record,
            'permissions' => $permissions,
        ];
    }

    // -------------------------------------------------------------------------
    // Token revocation
    // -------------------------------------------------------------------------

    /**
     * Revoke a token by its primary key.
     * Verifies ownership before revoking.
     * Returns false if the token was not found, already revoked, or not owned by userId.
     */
    public function revoke(int $tokenId, int $userId): bool
    {
        $record = $this->tokens->findById($tokenId);

        if ($record === null) {
            return false;
        }

        if ((int) $record['user_id'] !== $userId) {
            return false;
        }

        return $this->tokens->revoke($tokenId);
    }

    // -------------------------------------------------------------------------
    // Token listing (safe — no hashes)
    // -------------------------------------------------------------------------

    public function listForUser(int $userId): array
    {
        return $this->tokens->findByUserId($userId);
    }

    // -------------------------------------------------------------------------

    private function safeUser(array $user): array
    {
        return [
            'id'         => (int) $user['id'],
            'username'   => $user['username'],
            'email'      => $user['email'],
            'is_active'  => (bool) $user['is_active'],
            'created_at' => $user['created_at'],
            'updated_at' => $user['updated_at'],
        ];
    }
}
