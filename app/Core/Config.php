<?php

namespace App\Core;

/**
 * Configuration loader with local.php override support.
 *
 * Loading order for Config::load('database'), for example:
 *
 *   1. config/database.php   — base defaults, committed to version control
 *   2. config/local.php      — optional local overrides, NOT committed
 *                              (only the 'database' key is applied)
 *
 * The merge is a shallow-recursive replace: array_replace_recursive means
 * scalar values in local.php replace their counterpart in the base file,
 * while nested arrays are merged key-by-key rather than replaced wholesale.
 *
 * Example: if config/database.php defines mysql.host = '127.0.0.1' and
 * config/local.php defines database.mysql.host = 'db.prod', only the host
 * value changes — all other mysql keys are preserved from the base file.
 */
class Config
{
    /** Cached results of fully-merged config files. */
    private static array $cache = [];

    /**
     * Cached contents of config/local.php.
     * null = not yet loaded; [] = loaded but file absent or empty.
     */
    private static ?array $local = null;

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Load and return a merged config array for the given file name.
     *
     * @param  string $file  Config file name without .php (e.g. 'database')
     * @return array
     * @throws \RuntimeException if the base config file does not exist.
     */
    public static function load(string $file): array
    {
        if (!isset(self::$cache[$file])) {
            self::$cache[$file] = self::resolve($file);
        }

        return self::$cache[$file];
    }

    /**
     * Retrieve a single key from a config file, with an optional default.
     *
     * @param  string $file    Config file name (e.g. 'app')
     * @param  string|null $key  Top-level key to retrieve
     * @param  mixed  $default  Returned when the key is absent
     */
    public static function get(string $file, ?string $key = null, mixed $default = null): mixed
    {
        $data = self::load($file);

        if ($key === null) {
            return $data;
        }

        return $data[$key] ?? $default;
    }

    /**
     * Flush the cache. Useful in tests or when re-loading after a write.
     */
    public static function flush(): void
    {
        self::$cache = [];
        self::$local = null;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    private static function resolve(string $file): array
    {
        $base = self::requireBase($file);
        $over = self::localOverrides($file);

        if (empty($over)) {
            return $base;
        }

        return array_replace_recursive($base, $over);
    }

    private static function requireBase(string $file): array
    {
        $path = __DIR__ . '/../../config/' . $file . '.php';

        if (!file_exists($path)) {
            throw new \RuntimeException("Config file not found: config/{$file}.php");
        }

        $data = require $path;

        if (!is_array($data)) {
            throw new \RuntimeException("Config file must return an array: config/{$file}.php");
        }

        return $data;
    }

    /**
     * Return the portion of config/local.php that applies to $file,
     * or an empty array if local.php is absent or has no key for $file.
     */
    private static function localOverrides(string $file): array
    {
        $local = self::loadLocal();

        if (!isset($local[$file]) || !is_array($local[$file])) {
            return [];
        }

        return $local[$file];
    }

    /**
     * Load config/local.php once and cache it.
     */
    private static function loadLocal(): array
    {
        if (self::$local !== null) {
            return self::$local;
        }

        $path = __DIR__ . '/../../config/local.php';

        if (!file_exists($path)) {
            self::$local = [];
            return self::$local;
        }

        $data = require $path;

        self::$local = is_array($data) ? $data : [];

        return self::$local;
    }
}
