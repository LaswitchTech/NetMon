<?php

namespace App\Core;

abstract class Controller
{
    protected Container $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    /**
     * Send a JSON response and terminate.
     */
    protected function json(mixed $data, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: application/json');
        echo json_encode($data);
    }

    /**
     * Send a plain text response and terminate.
     */
    protected function text(string $body, int $status = 200): void
    {
        http_response_code($status);
        header('Content-Type: text/plain');
        echo $body;
    }

    /**
     * Retrieve a value from the raw request body (JSON payload).
     * Returns null if the key is absent or the body is not valid JSON.
     */
    protected function input(string $key, mixed $default = null): mixed
    {
        static $body = null;

        if ($body === null) {
            $raw  = file_get_contents('php://input');
            $body = json_decode($raw, true) ?? [];
        }

        return $body[$key] ?? $default;
    }

    /**
     * Retrieve a value from $_GET or $_POST.
     */
    protected function param(string $key, mixed $default = null): mixed
    {
        return $_REQUEST[$key] ?? $default;
    }
}
