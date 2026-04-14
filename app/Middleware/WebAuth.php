<?php

namespace App\Middleware;

use App\Auth\AuthService;
use App\Core\Container;
use App\Core\Gate;
use App\Core\MiddlewareInterface;

/**
 * Browser-facing session guard.
 *
 * Functionally identical to SessionAuth but designed for browser (HTML) routes:
 * on authentication failure it redirects to /auth/login instead of returning
 * a 401 JSON response.
 *
 * Use this middleware for routes that serve HTML pages.
 * Use SessionAuth for AJAX / JSON API routes.
 *
 * On success: writes the same 'principal' envelope to the container as SessionAuth:
 *   [
 *     'user'        => [...],
 *     'auth_method' => 'session',
 *     'token'       => null,
 *     'permissions' => ['admin', 'users.view', ...],
 *   ]
 */
class WebAuth implements MiddlewareInterface
{
    private Container $container;

    public function __construct(Container $container, ?string $arg = null)
    {
        $this->container = $container;
    }

    public function handle(array $params, callable $next): void
    {
        /** @var AuthService $auth */
        $auth = $this->container->get('auth');
        $user = $auth->user();

        if ($user === null) {
            header('Location: /auth/login', true, 302);
            exit;
        }

        /** @var Gate $gate */
        $gate        = $this->container->get('gate');
        $permissions = $gate->permissionsForUser($user['id']);

        $this->container->set('principal', [
            'user'        => $user,
            'auth_method' => 'session',
            'token'       => null,
            'permissions' => $permissions,
        ]);

        $next($params);
    }
}
