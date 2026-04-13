<?php

namespace App\Core\Installer;

/**
 * Checks that the server environment meets the application's prerequisites.
 *
 * Returns a structured result array suitable for display in both the CLI
 * installer and the web setup wizard.
 *
 * Result shape:
 *   [
 *     'ok'     => bool,         // false if any required check failed
 *     'checks' => [
 *       [
 *         'label'  => string,          // human-readable label
 *         'status' => 'pass'|'fail'|'optional',
 *         'detail' => string|null,     // e.g. current PHP version
 *         'fix'    => string|null,     // actionable fix hint (required fails only)
 *       ],
 *       ...
 *     ],
 *   ]
 */
class EnvironmentChecker
{
    private const MINIMUM_PHP = '8.1.0';

    private const REQUIRED_EXTENSIONS = [
        'pdo'       => 'PDO database abstraction layer',
        'pdo_sqlite'=> 'SQLite database driver',
        'json'      => 'JSON encode/decode support',
        'openssl'   => 'Secure token generation',
        'mbstring'  => 'Multibyte string handling',
    ];

    private const OPTIONAL_EXTENSIONS = [
        'pdo_mysql' => 'MySQL / MariaDB support (future)',
        'ldap'      => 'LDAP authentication (future)',
        'imap'      => 'IMAP authentication (future)',
    ];

    // -------------------------------------------------------------------------

    public function check(): array
    {
        $checks = [];
        $ok     = true;

        // PHP version
        $phpPass = version_compare(PHP_VERSION, self::MINIMUM_PHP, '>=');
        $checks[] = [
            'label'  => 'PHP ' . self::MINIMUM_PHP . '+',
            'status' => $phpPass ? 'pass' : 'fail',
            'detail' => PHP_VERSION,
            'fix'    => $phpPass
                ? null
                : 'Upgrade PHP to ' . self::MINIMUM_PHP . ' or newer.',
        ];
        if (!$phpPass) {
            $ok = false;
        }

        // Required extensions
        foreach (self::REQUIRED_EXTENSIONS as $ext => $purpose) {
            $loaded   = extension_loaded($ext);
            $checks[] = [
                'label'  => 'ext-' . $ext,
                'status' => $loaded ? 'pass' : 'fail',
                'detail' => $loaded ? $purpose : null,
                'fix'    => $loaded
                    ? null
                    : "Enable the {$ext} extension in php.ini.",
            ];
            if (!$loaded) {
                $ok = false;
            }
        }

        // Optional extensions
        foreach (self::OPTIONAL_EXTENSIONS as $ext => $purpose) {
            $loaded   = extension_loaded($ext);
            $checks[] = [
                'label'  => 'ext-' . $ext,
                'status' => $loaded ? 'pass' : 'optional',
                'detail' => $loaded ? $purpose : 'not loaded — ' . $purpose,
                'fix'    => null,
            ];
        }

        return ['ok' => $ok, 'checks' => $checks];
    }
}
