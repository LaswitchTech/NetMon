<?php

namespace App\Middleware;

use App\Core\Container;
use App\Core\Gate;
use App\Core\MiddlewareInterface;

/**
 * Browser-facing permission guard.
 *
 * Usage in route definitions:
 *   ['WebAuth', 'WebPermission:admin']
 *
 * Must run after WebAuth (which writes the 'principal' to the container).
 *
 * Unlike RequirePermission (which returns JSON), this middleware is designed
 * for HTML browser routes. It:
 *   - Redirects to /auth/login (302) if no principal is present
 *   - Returns an HTML 403 page if the principal lacks the required permission
 *
 * Use RequirePermission for AJAX / JSON API routes.
 * Use WebPermission for routes that serve HTML pages.
 */
class WebPermission implements MiddlewareInterface
{
    private Container $container;
    private string    $permission;

    public function __construct(Container $container, ?string $arg = null)
    {
        $this->container  = $container;
        $this->permission = $arg ?? '';
    }

    public function handle(array $params, callable $next): void
    {
        if ($this->permission === '') {
            http_response_code(500);
            echo '<h1>500 — WebPermission middleware has no permission configured</h1>';
            return;
        }

        if (!$this->container->has('principal')) {
            header('Location: /auth/login', true, 302);
            exit;
        }

        $principal = $this->container->get('principal');

        /** @var Gate $gate */
        $gate = $this->container->get('gate');

        if (!$gate->can($principal, $this->permission)) {
            http_response_code(403);
            header('Content-Type: text/html; charset=utf-8');
            echo $this->render403($this->permission);
            return;
        }

        $next($params);
    }

    private function render403(string $permission): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>403 Forbidden</title>
    <link rel="stylesheet" href="/assets/vendor/bootstrap/5.3.3/css/bootstrap.min.css">
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body class="d-flex align-items-center justify-content-center min-vh-100 bg-body">
    <div class="text-center" style="max-width:420px;">
        <div class="display-1 fw-bold text-muted mb-3">403</div>
        <h1 class="h4 mb-2">Access Denied</h1>
        <p class="text-muted mb-4">
            You do not have the required permission
            <code>{$permission}</code> to view this page.
        </p>
        <a href="/" class="btn btn-primary">Back to Dashboard</a>
    </div>
</body>
</html>
HTML;
    }
}
