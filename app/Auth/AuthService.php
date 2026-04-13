<?php

namespace App\Auth;

use App\Core\AuthProviderInterface;

/**
 * Manages the authentication session.
 *
 * Responsibilities:
 *   - Start and configure the PHP session
 *   - Delegate credential verification to the configured provider
 *   - Store and retrieve the authenticated user ID from the session
 *   - Provide a clean logout (session destruction)
 *
 * Not responsible for:
 *   - How credentials are verified (that is the provider's job)
 *   - Route protection (that will be a middleware concern)
 *   - Token-based API auth (separate concern, future work)
 */
class AuthService
{
    private AuthProviderInterface $provider;
    private array                 $config;
    private bool                  $sessionStarted = false;

    /** @var array|null In-request cache — avoid re-querying DB on every call */
    private ?array $cachedUser = null;

    public function __construct(AuthProviderInterface $provider, array $config)
    {
        $this->provider = $provider;
        $this->config   = $config;
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Attempt to log in with the given credentials.
     * Returns the safe user array on success, null on failure.
     */
    public function login(array $credentials): ?array
    {
        $user = $this->provider->attempt($credentials);

        if ($user === null) {
            return null;
        }

        $this->startSession();

        // Regenerate session ID to prevent session fixation attacks
        session_regenerate_id(true);

        $_SESSION['user_id'] = $user['id'];

        $this->cachedUser = $user;

        return $user;
    }

    /**
     * Log out the current user and destroy their session.
     */
    public function logout(): void
    {
        $this->startSession();

        $_SESSION = [];

        // Expire the session cookie immediately
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }

        session_destroy();

        $this->sessionStarted = false;
        $this->cachedUser     = null;
    }

    /**
     * Return the currently authenticated user, or null if not logged in.
     * Re-fetches from the provider on the first call per request.
     */
    public function user(): ?array
    {
        if ($this->cachedUser !== null) {
            return $this->cachedUser;
        }

        $this->startSession();

        if (empty($_SESSION['user_id'])) {
            return null;
        }

        $user = $this->provider->getUserById((int) $_SESSION['user_id']);

        // If the user no longer exists or was deactivated, clear the session
        if ($user === null) {
            unset($_SESSION['user_id']);
            return null;
        }

        $this->cachedUser = $user;

        return $user;
    }

    /**
     * Return true if a user is currently logged in.
     */
    public function check(): bool
    {
        return $this->user() !== null;
    }

    // -------------------------------------------------------------------------
    // Session management
    // -------------------------------------------------------------------------

    private function startSession(): void
    {
        if ($this->sessionStarted || session_status() === PHP_SESSION_ACTIVE) {
            $this->sessionStarted = true;
            return;
        }

        $sessionCfg = $this->config['session'] ?? [];

        session_name($sessionCfg['name'] ?? 'netmon_session');

        session_set_cookie_params([
            'lifetime' => (int) ($sessionCfg['lifetime'] ?? 7200),
            'path'     => '/',
            'secure'   => (bool) ($sessionCfg['secure'] ?? false),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);

        // Reject unrecognised session IDs (helps prevent session fixation)
        ini_set('session.use_strict_mode', '1');

        session_start();

        $this->sessionStarted = true;
    }
}
