<?php

namespace App\Models;

use App\Core\DatabaseInterface;

/**
 * Raw DB access for the system_settings table.
 *
 * Stores configuration values that administrators can override at runtime
 * through the Admin UI.  The service layer (SystemSettingService) handles
 * typed access, fallback chain, and validation.
 *
 * Storage convention:
 *   - Strings are stored as-is.
 *   - Booleans are stored as '1' (true) or '0' (false).
 *   - Integers are stored as their decimal string representation.
 *
 * All keys are lowercase dot-separated identifiers (e.g. 'app.name').
 */
class SystemSettingRepository
{
    private DatabaseInterface $db;

    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    /**
     * Return the stored string value for a key, or null if not set.
     */
    public function get(string $key): ?string
    {
        $row = $this->db->fetchOne(
            'SELECT value FROM system_settings WHERE key = ? LIMIT 1',
            [$key]
        );

        return $row !== null ? $row['value'] : null;
    }

    /**
     * Return all settings as a key → value map.
     *
     * @return array<string, string>
     */
    public function getAll(): array
    {
        $rows = $this->db->fetch(
            'SELECT key, value FROM system_settings ORDER BY key ASC',
            []
        );

        $result = [];
        foreach ($rows as $row) {
            $result[$row['key']] = $row['value'];
        }

        return $result;
    }

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    /**
     * Insert or update a setting.
     *
     * Uses INSERT OR REPLACE so calling set() on an existing key updates
     * rather than errors.
     */
    public function set(string $key, string $value): void
    {
        $now = date('Y-m-d H:i:s');

        // Preserve created_at on updates by checking existence first.
        $existing = $this->db->fetchOne(
            'SELECT created_at FROM system_settings WHERE key = ? LIMIT 1',
            [$key]
        );

        $createdAt = $existing ? $existing['created_at'] : $now;

        $this->db->execute(
            'INSERT OR REPLACE INTO system_settings (key, value, created_at, updated_at)
             VALUES (?, ?, ?, ?)',
            [$key, $value, $createdAt, $now]
        );
    }
}
