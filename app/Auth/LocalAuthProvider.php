<?php

namespace App\Auth;

use App\Core\AuthProviderInterface;
use App\Models\UserRepository;

/**
 * Authenticates users stored in the local database with a hashed password.
 *
 * Credentials expected:
 *   - 'identity'  username or email address
 *   - 'password'  plain-text password (verified against password_hash)
 */
class LocalAuthProvider implements AuthProviderInterface
{
    private UserRepository $users;

    public function __construct(UserRepository $users)
    {
        $this->users = $users;
    }

    public function attempt(array $credentials): ?array
    {
        $identity = trim($credentials['identity'] ?? '');
        $password = $credentials['password'] ?? '';

        if ($identity === '' || $password === '') {
            return null;
        }

        // Accept either username or email as the identity
        $user = str_contains($identity, '@')
            ? $this->users->findByEmail($identity)
            : $this->users->findByUsername($identity);

        if ($user === null) {
            // Hash a dummy value to prevent timing-based user enumeration
            password_verify($password, '$2y$12$invalidhashpaddingtopreventitenumeration000000000000000');
            return null;
        }

        if (!password_verify($password, $user['password_hash'])) {
            return null;
        }

        return $this->safeUser($user);
    }

    public function getUserById(int $id): ?array
    {
        $user = $this->users->findById($id);

        return $user !== null ? $this->safeUser($user) : null;
    }

    // -------------------------------------------------------------------------

    /**
     * Strip sensitive fields before returning a user to the service layer.
     */
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
