<?php

/**
 * Notifications module configuration.
 *
 * Covers the module-level delivery channels (email, future SMS).
 * This is separate from config/notifications.php which controls the legacy
 * alert dispatch channels (log, webhook) used by scripts/monitor.php.
 *
 * Values are read from environment variables where available, with safe
 * defaults for development (email disabled by default).
 *
 * To override locally without modifying this file, add a
 * 'notifications-module' key to config/local.php:
 *
 *   return [
 *       'notifications-module' => [
 *           'email' => [
 *               'enabled'      => true,
 *               'from_address' => 'netmon@example.com',
 *               'from_name'    => 'NetMon Alerts',
 *               'smtp_host'    => 'smtp.example.com',
 *               'smtp_port'    => 587,
 *               'smtp_user'    => 'netmon@example.com',
 *               'smtp_pass'    => 'secret',
 *               'encryption'   => 'tls',
 *           ],
 *       ],
 *   ];
 */
return [

    // -------------------------------------------------------------------------
    // Email channel — SMTP delivery for user-facing notifications.
    //
    // encryption:
    //   'tls'  → STARTTLS on a plain connection (most common; typically port 587)
    //   'ssl'  → SMTPS — TLS from first byte (typically port 465)
    //   'none' → plain SMTP, no encryption (development/relay only)
    //
    // Set NOTIFY_EMAIL_ENABLED=true plus the SMTP settings to activate delivery.
    // -------------------------------------------------------------------------
    'email' => [

        // Master switch — set false to skip all email delivery.
        'enabled' => (bool) filter_var(
            getenv('NOTIFY_EMAIL_ENABLED') ?: 'false',
            FILTER_VALIDATE_BOOLEAN
        ),

        // Sender identity shown in the From: header.
        'from_address' => getenv('NOTIFY_EMAIL_FROM')      ?: '',
        'from_name'    => getenv('NOTIFY_EMAIL_FROM_NAME') ?: 'NetMon',

        // SMTP connection.
        'smtp_host'   => getenv('NOTIFY_SMTP_HOST') ?: '',
        'smtp_port'   => (int) (getenv('NOTIFY_SMTP_PORT') ?: 587),
        'smtp_user'   => getenv('NOTIFY_SMTP_USER') ?: '',
        'smtp_pass'   => getenv('NOTIFY_SMTP_PASS') ?: '',

        // Transport encryption: 'tls' (STARTTLS), 'ssl' (SMTPS), or 'none'.
        'encryption' => getenv('NOTIFY_SMTP_ENCRYPTION') ?: 'tls',

    ],

];
