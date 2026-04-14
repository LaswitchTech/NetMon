<?php

/**
 * Notification channel configuration.
 *
 * Controls which channels are active and how throttling works.
 * Values are read from environment variables where available, with
 * sensible defaults for development (log channel on, webhook off).
 *
 * To override locally, add a 'notifications' key to config/local.php:
 *
 *   return [
 *       'notifications' => [
 *           'throttle_seconds' => 900,
 *           'channels' => [
 *               'webhook' => [
 *                   'enabled' => true,
 *                   'url'     => 'https://hooks.slack.com/services/...',
 *               ],
 *           ],
 *       ],
 *   ];
 */
return [

    // Minimum seconds between notifications for the same open alert.
    // Default: 900 (15 minutes). Override with NOTIFY_THROTTLE_SECONDS env var.
    'throttle_seconds' => (int) (getenv('NOTIFY_THROTTLE_SECONDS') ?: 900),

    'channels' => [

        // -------------------------------------------------------------------------
        // Log channel — always available, no external dependencies.
        // Writes to storage/logs/notifications.log by default.
        // -------------------------------------------------------------------------
        'log' => [
            'enabled' => (bool) filter_var(
                getenv('NOTIFY_LOG_ENABLED') ?: 'true',
                FILTER_VALIDATE_BOOLEAN
            ),
            'path' => getenv('NOTIFY_LOG_PATH') ?: __DIR__ . '/../storage/logs/notifications.log',
        ],

        // -------------------------------------------------------------------------
        // Webhook channel — HTTP POST JSON to any webhook endpoint.
        // Compatible with Slack, Mattermost, custom integrations, etc.
        // Disabled by default; configure NOTIFY_WEBHOOK_URL to activate.
        // -------------------------------------------------------------------------
        'webhook' => [
            'enabled' => (bool) filter_var(
                getenv('NOTIFY_WEBHOOK_ENABLED') ?: 'false',
                FILTER_VALIDATE_BOOLEAN
            ),
            'url'     => getenv('NOTIFY_WEBHOOK_URL') ?: '',
            'timeout' => (int) (getenv('NOTIFY_WEBHOOK_TIMEOUT') ?: 5),
        ],

    ],
];
