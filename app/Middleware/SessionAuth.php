<?php

namespace App\Middleware;

use App\Auth\AuthService;
use App\Core\Container;
use App\Core\Gate;
use App\Core\MiddlewareInterface;

/**
 * Requires a valid session.
 *
 * On success: writes a 'principal' to the container:
 *   [
 *     'user'        => [...],
 *     'auth_method' => 'session',
 *     'token'       => null,
 *     'permissions' => ['admin', 'users.view', ...],
 *   ]
 *
 * On failure: terminates with 401 JSON.
 */
class SessionAuth implements MiddlewareInterface
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
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Authentication required']);
            return;
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
