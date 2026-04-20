<?php

namespace App\Modules\Notifications\Models;

use App\Core\DatabaseInterface;

/**
 * Per-user notification channel preferences.
 *
 * A missing row means the channel is enabled (opt-out model).
 * Phase 1 stores these preferences and exposes them in the Profile UI.
 * The delivery worker will honour them in a future phase.
 */
class NotificationPreferenceRepository
{
    private DatabaseInterface $db;

    /** Channels supported in Phase 1. */
    public const CHANNELS = ['in_app', 'email'];

    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    /**
     * Return all channel preferences for a user.
     *
     * Returns an array keyed by channel name with bool values.
     * Channels with no stored preference default to true (enabled).
     *
     * @return array<string, bool>  e.g. ['in_app' => true, 'email' => false]
     */
    public function getAllForUser(int $userId): array
    {
        $rows = $this->db->fetch(
            "SELECT channel, enabled
             FROM   notification_preferences
             WHERE  user_id = ?",
            [$userId]
        );

        // Start with all channels enabled.
        $prefs = array_fill_keys(self::CHANNELS, true);

        foreach ($rows as $row) {
            if (in_array($row['channel'], self::CHANNELS, true)) {
                $prefs[$row['channel']] = (bool) $row['enabled'];
            }
        }

        return $prefs;
    }

    /**
     * Return whether a specific channel is enabled for a user.
     *
     * Defaults to true when no row exists (opt-out model).
     */
    public function isChannelEnabled(int $userId, string $channel): bool
    {
        $row = $this->db->fetchOne(
            "SELECT enabled
             FROM   notification_preferences
             WHERE  user_id = ? AND channel = ?",
            [$userId, $channel]
        );

        return $row === null ? true : (bool) $row['enabled'];
    }

    /**
     * Set the enabled flag for a specific channel.
     *
     * Uses INSERT OR REPLACE so it is safe to call repeatedly.
     */
    public function setChannelEnabled(int $userId, string $channel, bool $enabled): void
    {
        $this->db->execute(
            "INSERT OR REPLACE INTO notification_preferences
                 (user_id, channel, enabled, updated_at)
             VALUES (?, ?, ?, ?)",
            [$userId, $channel, $enabled ? 1 : 0, date('Y-m-d H:i:s')]
        );
    }

    /**
     * Save multiple channel preferences at once.
     *
     * @param array<string, bool> $prefs  Channel name → enabled flag
     */
    public function saveAllForUser(int $userId, array $prefs): void
    {
        $now = date('Y-m-d H:i:s');

        foreach ($prefs as $channel => $enabled) {
            if (!in_array($channel, self::CHANNELS, true)) {
                continue;
            }

            $this->db->execute(
                "INSERT OR REPLACE INTO notification_preferences
                     (user_id, channel, enabled, updated_at)
                 VALUES (?, ?, ?, ?)",
                [$userId, $channel, $enabled ? 1 : 0, $now]
            );
        }
    }
}
