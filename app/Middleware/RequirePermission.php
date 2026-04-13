<?php

namespace App\Middleware;

use App\Core\Container;
use App\Core\Gate;
use App\Core\MiddlewareInterface;

/**
 * Requires the authenticated principal to hold a specific permission.
 *
 * Usage in route definitions:
 *   'RequirePermission:users.view'
 *
 * Must run after an auth middleware (SessionAuth or TokenAuth) that has
 * already written 'principal' to the container.
 *
 * 401 — no principal present (not authenticated)
 * 403 — principal present but permission missing (authenticated but forbidden)
 */
class RequirePermission implements MiddlewareInterface
{
    private Container   $container;
    private string      $permission;

    public function __construct(Container $container, ?string $arg = null)
    {
        $this->container  = $container;
        $this->permission = $arg ?? '';
    }

    public function handle(array $params, callable $next): void
    {
        if ($this->permission === '') {
            // Misconfigured route — treat as a server error rather than silently pass
            http_response_code(500);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'RequirePermission middleware has no permission configured']);
            return;
        }

        if (!$this->container->has('principal')) {
            http_response_code(401);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Authentication required']);
            return;
        }

        $principal = $this->container->get('principal');

        /** @var Gate $gate */
        $gate = $this->container->get('gate');

        if (!$gate->can($principal, $this->permission)) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode(['error' => 'Forbidden — missing permission: ' . $this->permission]);
            return;
        }

        $next($params);
    }
}
