<?php

namespace App\Core;

class Router
{
    private array $routes = [];
    private Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function get(string $path, string $handler, array $middleware = []): void
    {
        $this->add('GET', $path, $handler, $middleware);
    }

    public function post(string $path, string $handler, array $middleware = []): void
    {
        $this->add('POST', $path, $handler, $middleware);
    }

    public function put(string $path, string $handler, array $middleware = []): void
    {
        $this->add('PUT', $path, $handler, $middleware);
    }

    public function delete(string $path, string $handler, array $middleware = []): void
    {
        $this->add('DELETE', $path, $handler, $middleware);
    }

    private function add(string $method, string $path, string $handler, array $middleware = []): void
    {
        $this->routes[] = [
            'method'     => strtoupper($method),
            'path'       => $path,
            'handler'    => $handler,
            'middleware' => $middleware,
        ];
    }

    /**
     * Dispatch the current request.
     * Handler format: "Controller@method"
     */
    public function dispatch(string $method, string $uri): void
    {
        $uri = strtok($uri, '?'); // strip query string

        foreach ($this->routes as $route) {
            [$pattern, $paramNames] = $this->compile($route['path']);

            if ($route['method'] !== strtoupper($method)) {
                continue;
            }

            if (!preg_match($pattern, $uri, $matches)) {
                continue;
            }

            // Extract named params
            $params = [];
            foreach ($paramNames as $name) {
                $params[$name] = $matches[$name] ?? null;
            }

            $this->call($route['handler'], $route['middleware'], $params);
            return;
        }

        throw new \OutOfBoundsException("No route matched: {$method} {$uri}");
    }

    /**
     * Convert a route path like /users/{id} into a regex.
     * Returns [pattern, paramNames].
     */
    private function compile(string $path): array
    {
        $paramNames = [];

        $pattern = preg_replace_callback('/\{(\w+)\}/', function ($m) use (&$paramNames) {
            $paramNames[] = $m[1];
            return '(?P<' . $m[1] . '>[^/]+)';
        }, $path);

        $pattern = '#^' . $pattern . '$#';

        return [$pattern, $paramNames];
    }

    private function call(string $handler, array $middleware, array $params): void
    {
        $action = function (array $p) use ($handler): void {
            [$className, $method] = explode('@', $handler);

            // Qualified path: 'NetMon\Controllers\HomeController@index'
            //   → 'App\NetMon\Controllers\HomeController'
            // Bare name:      'HomeController@index'
            //   → 'App\Controllers\HomeController'  (backward-compatible default)
            $fqcn = str_contains($className, '\\')
                ? 'App\\' . $className
                : 'App\\Controllers\\' . $className;

            if (!class_exists($fqcn)) {
                throw new \RuntimeException("Controller not found: {$fqcn}");
            }

            $controller = new $fqcn($this->container);

            if (!method_exists($controller, $method)) {
                throw new \RuntimeException("Method not found: {$fqcn}::{$method}");
            }

            $controller->$method($p);
        };

        if (empty($middleware)) {
            $action($params);
            return;
        }

        $this->runStack($middleware, $params, $action);
    }

    /**
     * Build and execute a middleware chain.
     *
     * Middleware strings may carry an argument after a colon:
     *   'RequirePermission:users.view'  →  new RequirePermission($container, 'users.view')
     *
     * The chain is built right-to-left so the first middleware in the array
     * is the outermost (runs first).
     */
    private function runStack(array $middleware, array $params, callable $action): void
    {
        $chain = $action;

        foreach (array_reverse($middleware) as $mw) {
            $parts    = explode(':', $mw, 2);
            $fqcn     = 'App\\Middleware\\' . $parts[0];
            $arg      = $parts[1] ?? null;

            if (!class_exists($fqcn)) {
                throw new \RuntimeException("Middleware not found: {$fqcn}");
            }

            $instance = new $fqcn($this->container, $arg);
            $inner    = $chain;

            $chain = function (array $p) use ($instance, $inner): void {
                $instance->handle($p, $inner);
            };
        }

        $chain($params);
    }
}
