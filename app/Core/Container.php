<?php

namespace App\Core;

class Container
{
    private array $bindings = [];

    public function set(string $key, mixed $value): void
    {
        $this->bindings[$key] = $value;
    }

    public function get(string $key): mixed
    {
        if (!$this->has($key)) {
            throw new \RuntimeException("Container: no binding for '{$key}'");
        }

        return $this->bindings[$key];
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->bindings);
    }
}
