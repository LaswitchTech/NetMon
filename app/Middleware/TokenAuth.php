<?php

namespace App\Middleware;

use App\Auth\TokenService;
use App\Core\Container;
use App\Core\MiddlewareInterface;

/**
 * Requires a valid API token in the Authorization header.
 *
 * Expected header format:
 *   Authorization: Bearer <raw_token>
 *
 * On success: writes a 'principal' to the container (see TokenService::verify).
 * On failure: terminates with 401 JSON.
 */
class TokenAuth implements MiddlewareInterface
{
    private Container $container;

    public function __construct(Container $container, ?string $arg = null)
    {
        $this->container = $container;
    }

    public function handle(array $params, callable $next): void
    {
        $raw = $this->extractToken();

        if ($raw === null) {
            $this->deny('Authorization header missing or malformed');
            return;
        }

        /** @var TokenService $tokenService */
        $tokenService = $this->container->get('tokens');
        $principal    = $tokenService->verify($raw);

        if ($principal === null) {
            $this->deny('Invalid or expired token');
            return;
        }

        $this->container->set('principal', $principal);

        $next($params);
    }

    // -------------------------------------------------------------------------

    /**
     * Extract the raw token from the Authorization header.
     * Returns null if the header is absent or not in Bearer format.
     */
    private function extractToken(): ?string
    {
        $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';

        if ($header === '') {
            // Some SAPI configurations expose the header differently
            $header = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
        }

        if (!str_starts_with($header, 'Bearer ')) {
            return null;
        }

        $token = trim(substr($header, 7));

        return $token !== '' ? $token : null;
    }

    private function deny(string $message): void
    {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => $message]);
    }
}
