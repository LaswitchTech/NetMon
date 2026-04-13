<?php

namespace App\Core;

/**
 * Contract for all authentication providers.
 *
 * A provider is responsible for one thing only: verifying credentials and
 * returning a user record.  Session management, rate limiting, and all other
 * concerns live outside the provider.
 *
 * Future providers (LDAP, IMAP, OAuth…) implement this interface.
 */
interface AuthProviderInterface
{
    /**
     * Attempt to authenticate with the given credentials.
     *
     * @param  array $credentials  Provider-specific key/value pairs.
     *                             LocalAuthProvider expects 'identity' and 'password'.
     * @return array|null          A safe user array on success (no password_hash),
     *                             or null on failure.
     */
    public function attempt(array $credentials): ?array;

    /**
     * Re-hydrate a user by their primary key.
     * Used to reload the user on every request from the session-stored ID.
     *
     * Returns null if the user no longer exists or is inactive.
     */
    public function getUserById(int $id): ?array;
}
