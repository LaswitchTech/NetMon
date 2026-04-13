<?php

/**
 * NetMon CLI Uninstall / Reset Helper
 *
 * Usage:
 *   php scripts/uninstall.php
 *
 * This script resets a local/dev installation so setup can be run again.
 * It is intentionally conservative and only removes install/runtime artifacts.
 * It does NOT remove baseline project files.
 *
 * Exit codes:
 *   0   Reset complete
 *   1   User cancelled
 *   2   Failed to update .env
 *   3   Failed to remove install lock
 *   4   Failed to remove config/local.php
 *   5   Failed to remove SQLite database
 *   6   Unexpected reset failure
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Autoloader
// ---------------------------------------------------------------------------
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    $base   = __DIR__ . '/../app/';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file     = $base . str_replace('\\', '/', $relative) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// ---------------------------------------------------------------------------
// Bootstrap environment
// ---------------------------------------------------------------------------
use App\Core\Config;
use App\Core\Env;
use App\Core\Installer\ConfigWriter;
use App\Core\Installer\InstallLock;

$rootPath = realpath(__DIR__ . '/..');
if ($rootPath === false) {
    fwrite(STDERR, "ERROR: Unable to resolve project root." . PHP_EOL);
    exit(6);
}

$envPath    = $rootPath . '/.env';
$lockPath   = $rootPath . '/storage/installed.lock';
$localPath  = $rootPath . '/config/local.php';

Env::load($envPath);
Config::flush();

$appName       = Env::get('APP_NAME', 'Application');
$currentUrl    = Env::get('APP_URL', 'http://localhost');
$dbConfig      = Config::load('database');
$sqlitePath    = resolveSqlitePath($rootPath, $dbConfig);
$installLock   = new InstallLock($rootPath);
$configWriter  = new ConfigWriter($rootPath);

// ---------------------------------------------------------------------------
// Header
// ---------------------------------------------------------------------------
out('');
out('╔══════════════════════════════════════════════╗');
out("║   {$appName} — Uninstall / Reset" . str_repeat(' ', max(0, 44 - strlen($appName) - 22)) . ' ║');
out('╚══════════════════════════════════════════════╝');
out('');
out('  This helper removes local installation artifacts so setup can be run again.');
out('');
out('  It may remove:');
out('    - storage/installed.lock');
out('    - config/local.php');
out('    - SQLite database file');
out('    - APP_INSTALLED=true (set back to false in .env)');
out('');
out('  Current state:');
out('    App URL       : ' . $currentUrl);
out('    Installed     : ' . ($installLock->isInstalled() ? 'yes' : 'no'));
out('    Lock file      : ' . (file_exists($lockPath) ? 'present' : 'missing'));
out('    Local config   : ' . (file_exists($localPath) ? 'present' : 'missing'));
out('    SQLite DB      : ' . ($sqlitePath !== null && file_exists($sqlitePath) ? $sqlitePath : 'not found / not configured'));
out('');
out('  This action is intended for development/testing resets only.');
out('');

$confirm = prompt('  Proceed with uninstall/reset? [y/N]: ');
if (!in_array(strtolower($confirm), ['y', 'yes'], true)) {
    out('  Reset cancelled.');
    exit(1);
}

out('');
phase('1/4', 'Updating installation state in .env');
try {
    $configWriter->writeEnv($envPath, [
        'APP_INSTALLED' => 'false',
    ]);
    out('  [✓] APP_INSTALLED set to false');
} catch (\Throwable $e) {
    out('  [✗] Failed to update .env: ' . $e->getMessage());
    exit(2);
}

out('');
phase('2/4', 'Removing install lock');
if (file_exists($lockPath)) {
    if (!@unlink($lockPath) && file_exists($lockPath)) {
        out('  [✗] Could not remove ' . $lockPath);
        exit(3);
    }
    out('  [✓] Removed ' . $lockPath);
} else {
    out('  [✓] No install lock present');
}

out('');
phase('3/4', 'Removing local install configuration');
if (file_exists($localPath)) {
    if (!@unlink($localPath) && file_exists($localPath)) {
        out('  [✗] Could not remove ' . $localPath);
        exit(4);
    }
    out('  [✓] Removed ' . $localPath);
} else {
    out('  [✓] No config/local.php present');
}

out('');
phase('4/4', 'Removing SQLite database');
if ($sqlitePath === null) {
    out('  [✓] No SQLite path configured; skipping');
} elseif (!file_exists($sqlitePath)) {
    out('  [✓] No SQLite database file present');
} else {
    if (!@unlink($sqlitePath) && file_exists($sqlitePath)) {
        out('  [✗] Could not remove SQLite database: ' . $sqlitePath);
        exit(5);
    }
    out('  [✓] Removed SQLite database: ' . $sqlitePath);
}

out('');
out('╔══════════════════════════════════════════════╗');
out('║   Reset complete                             ║');
out('╚══════════════════════════════════════════════╝');
out('');
out('  You can now rerun setup using:');
out('    - Web: ' . rtrim($currentUrl, '/') . '/setup');
out('    - CLI: php scripts/install.php');
out('');

exit(0);

// ===========================================================================
// Helpers
// ===========================================================================

function out(string $line): void
{
    echo $line . PHP_EOL;
}

function phase(string $step, string $label): void
{
    out("── Phase {$step}: {$label}");
}

function prompt(string $label): string
{
    echo $label;
    $value = fgets(STDIN);
    return $value === false ? '' : rtrim($value, "\r\n");
}

/**
 * Resolve the SQLite database path from merged config.
 *
 * Returns null when the active driver is not sqlite or the path is unavailable.
 */
function resolveSqlitePath(string $rootPath, array $dbConfig): ?string
{
    $driver = strtolower((string) ($dbConfig['driver'] ?? 'sqlite'));
    if ($driver !== 'sqlite') {
        return null;
    }

    $candidates = [
        $dbConfig['sqlite']['path'] ?? null,
        $dbConfig['sqlite']['database'] ?? null,
        $dbConfig['database'] ?? null,
        Env::get('DB_SQLITE_PATH'),
        Env::get('SQLITE_PATH'),
    ];

    foreach ($candidates as $candidate) {
        if (!is_string($candidate) || trim($candidate) === '') {
            continue;
        }

        $path = trim($candidate);
        if ($path[0] !== '/') {
            $path = $rootPath . '/' . ltrim($path, '/');
        }

        return $path;
    }

    return $rootPath . '/data/app.db';
}
