<?php

namespace App\Core;

/**
 * Contract for all HTTP middleware.
 *
 * Middleware receives the route params and a $next callable.
 * It must call $next($params) to continue the chain, or terminate
 * (e.g. respond with 401/403) to short-circuit it.
 *
 * Instantiation convention (enforced by the Router, not the interface):
 *   __construct(Container $container, ?string $arg = null)
 *
 * The optional $arg carries inline configuration from route definitions.
 * Example route: 'RequirePermission:users.view' → $arg = 'users.view'
 */
interface MiddlewareInterface
{
    public function handle(array $params, callable $next): void;
}
