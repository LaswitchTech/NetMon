<?php

/**
 * Monitoring configuration — base defaults.
 *
 * These values can be overridden per-environment via config/local.php:
 *
 *   return [
 *       'monitoring' => [
 *           'retention' => [
 *               'device_checks_days'        => 60,
 *               'service_checks_days'       => 60,
 *               'notification_history_days' => 180,
 *           ],
 *       ],
 *   ];
 */
return [

    /**
     * Retention policy — how many days of historical data to keep.
     *
     * Rows older than the configured threshold are deleted by scripts/cleanup.php.
     * The cleanup script must be scheduled externally (e.g. daily cron).
     *
     * Reducing these values will permanently delete older rows on the next
     * cleanup run. Increase them before running cleanup if you want to preserve
     * more history.
     */
    'retention' => [
        'device_checks_days'        => 30,
        'service_checks_days'       => 30,
        'notification_history_days' => 90,
    ],

];
