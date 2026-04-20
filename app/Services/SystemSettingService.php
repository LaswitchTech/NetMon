<?php

namespace App\Services;

use App\Core\Config;
use App\Models\SystemSettingRepository;

/**
 * Application settings service.
 *
 * Provides typed access to system_settings with a layered fallback chain:
 *
 *   1. DB row in system_settings (highest priority — admin-managed at runtime)
 *   2. config/local.php override (deployment-specific; not committed)
 *   3. .env variable (base application identity and defaults)
 *   4. Hardcoded application default (lowest priority)
 *
 * Config::load() already implements steps 2 + 3 (local.php merges over base
 * config files which read from .env).  This service handles step 1 by
 * checking the DB first; if no row exists it delegates to Config::load().
 *
 * Config::load() is NOT modified — existing callers are unaffected.
 *
 * Known setting keys (Phase 1):
 *
 *   app.name                    Application display name
 *   app.url                     Public-facing URL (no trailing slash)
 *   notifications.email_enabled Email delivery master switch (bool)
 *   monitoring.check_interval   Default check interval in seconds (int)
 *
 * Unknown keys (not in the KNOWN_KEYS list) have no config fallback;
 * they return their DB value or the provided $default.
 */
class SystemSettingService
{
    /**
     * Known settings with their [config_file, config_key] fallback paths.
     * null config values mean there is no file-based fallback for this key.
     */
    private const KNOWN_KEYS = [
        'app.name'                    => ['app',                  'name'],
        'app.url'                     => ['app',                  'url'],
        'notifications.email_enabled' => ['notifications-module', 'email.enabled'],
        'monitoring.check_interval'   => [null,                   null],
    ];

    /** Hardcoded defaults when no DB row and no config value exists. */
    private const DEFAULTS = [
        'app.name'                    => 'NetMon',
        'app.url'                     => 'http://localhost',
        'notifications.email_enabled' => false,
        'monitoring.check_interval'   => 60,
    ];

    private SystemSettingRepository $repo;

    public function __construct(SystemSettingRepository $repo)
    {
        $this->repo = $repo;
    }

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    /**
     * Get a setting value using the full fallback chain.
     *
     * Returns the raw string from DB, or the typed value from the config
     * fallback, or $default.
     */
    public function get(string $key, mixed $default = null): mixed
    {
        // 1. DB
        $dbValue = $this->repo->get($key);
        if ($dbValue !== null) {
            return $dbValue;
        }

        // 2+3. config/local.php → .env
        $configValue = $this->configFallback($key);
        if ($configValue !== null) {
            return $configValue;
        }

        // 4. Hardcoded default
        return self::DEFAULTS[$key] ?? $default;
    }

    /**
     * Get a string setting.
     */
    public function getString(string $key, string $default = ''): string
    {
        $val = $this->get($key);
        return $val !== null ? (string) $val : $default;
    }

    /**
     * Get a boolean setting.
     *
     * DB values '1', 'true', 'yes', 'on' are treated as true (case-insensitive).
     * Config/default values are cast with (bool).
     */
    public function getBool(string $key, bool $default = false): bool
    {
        // Check DB first — always a string if present.
        $dbValue = $this->repo->get($key);
        if ($dbValue !== null) {
            return in_array(strtolower(trim($dbValue)), ['1', 'true', 'yes', 'on'], true);
        }

        // Config fallback returns a typed PHP value.
        $configValue = $this->configFallback($key);
        if ($configValue !== null) {
            return (bool) $configValue;
        }

        // Hardcoded default.
        return isset(self::DEFAULTS[$key]) ? (bool) self::DEFAULTS[$key] : $default;
    }

    /**
     * Get an integer setting.
     */
    public function getInt(string $key, int $default = 0): int
    {
        // Check DB first.
        $dbValue = $this->repo->get($key);
        if ($dbValue !== null) {
            return (int) $dbValue;
        }

        // Config fallback.
        $configValue = $this->configFallback($key);
        if ($configValue !== null) {
            return (int) $configValue;
        }

        // Hardcoded default.
        return isset(self::DEFAULTS[$key]) ? (int) self::DEFAULTS[$key] : $default;
    }

    /**
     * Return all settings as a key → typed-value map.
     *
     * Merges DB values over config/default values so the caller gets the
     * effective values for all known keys without N separate calls.
     *
     * @return array<string, mixed>
     */
    public function getAll(): array
    {
        $result = [];

        foreach (array_keys(self::KNOWN_KEYS) as $key) {
            $result[$key] = $this->get($key);
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    /**
     * Persist a setting to the DB.
     *
     * Booleans are stored as '1' or '0'.
     * Other values are cast to string.
     */
    public function set(string $key, mixed $value): void
    {
        $str = is_bool($value) ? ($value ? '1' : '0') : (string) $value;
        $this->repo->set($key, $str);
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Return the config-file/env value for a known key, or null.
     *
     * For dotted config sub-keys (e.g. 'email.enabled'), walk down the array.
     */
    private function configFallback(string $key): mixed
    {
        if (!isset(self::KNOWN_KEYS[$key])) {
            return null;
        }

        [$configFile, $configKey] = self::KNOWN_KEYS[$key];

        if ($configFile === null || $configKey === null) {
            return null;
        }

        try {
            $config = Config::load($configFile);
        } catch (\RuntimeException $e) {
            return null;
        }

        // Walk dotted sub-keys (e.g. 'email.enabled' → $config['email']['enabled']).
        $segments = explode('.', $configKey);
        $value    = $config;

        foreach ($segments as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return null;
            }
            $value = $value[$segment];
        }

        return $value;
    }
}
