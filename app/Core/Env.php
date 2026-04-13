<?php

namespace App\Core;

/**
 * Minimal .env file parser.
 *
 * Reads KEY=VALUE pairs from a .env file and makes them available via
 * getenv(), $_ENV, and Env::get(). The .env file is optional — if it does
 * not exist, the application falls back to defaults defined in config files.
 *
 * Supported syntax:
 *   APP_NAME=NetMon           bare value
 *   APP_NAME="Net Mon"        double-quoted value (quotes stripped)
 *   APP_NAME='Net Mon'        single-quoted value (quotes stripped)
 *   # This is a comment       ignored
 *   BLANK_LINE=               empty value
 *
 * Not supported (intentionally kept minimal):
 *   Multi-line values, variable interpolation (${VAR}), export keyword.
 */
class Env
{
    private static bool  $loaded = false;
    private static array $values = [];

    // -------------------------------------------------------------------------
    // Loading
    // -------------------------------------------------------------------------

    /**
     * Load a .env file into the process environment.
     *
     * Safe to call multiple times — subsequent calls are no-ops.
     * Does not throw if the file is absent.
     */
    public static function load(string $path): void
    {
        if (self::$loaded) {
            return;
        }

        self::$loaded = true; // mark even if file is absent

        if (!file_exists($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip comments and blank lines
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            // Must contain an = sign
            if (!str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key   = trim($key);
            $value = trim($value);

            // Keys must be alphanumeric + underscore, starting with a letter or underscore
            if ($key === '' || !preg_match('/^[A-Z_][A-Z0-9_]*$/i', $key)) {
                continue;
            }

            // Strip matching surrounding quotes
            $value = self::stripQuotes($value);

            self::$values[$key] = $value;
            $_ENV[$key]         = $value;
            putenv("{$key}={$value}");
        }
    }

    // -------------------------------------------------------------------------
    // Access
    // -------------------------------------------------------------------------

    /**
     * Return an environment value by key.
     *
     * Checks in order: values loaded from .env, $_ENV, getenv().
     * Returns $default if the key is not found in any source.
     */
    public static function get(string $key, mixed $default = null): mixed
    {
        if (array_key_exists($key, self::$values)) {
            return self::$values[$key];
        }

        $env = getenv($key);
        return $env !== false ? $env : $default;
    }

    /**
     * Return an environment value coerced to a boolean.
     *
     * Truthy strings: 'true', '1', 'yes', 'on' (case-insensitive).
     * Everything else (including absent keys) is false.
     */
    public static function bool(string $key, bool $default = false): bool
    {
        $val = self::get($key, $default ? 'true' : 'false');
        return in_array(strtolower((string) $val), ['true', '1', 'yes', 'on'], true);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    private static function stripQuotes(string $value): string
    {
        if (strlen($value) < 2) {
            return $value;
        }

        $first = $value[0];
        $last  = $value[-1];

        if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
            return substr($value, 1, -1);
        }

        return $value;
    }
}
