<?php

/**
 * NetMon CLI Installer
 *
 * Usage:
 *   php scripts/install.php
 *
 * This script is a thin interactive wrapper around SetupService.
 * All installation logic lives in SetupService; this file handles
 * only user I/O and progress display.
 *
 * Exit codes:
 *   0   Installation complete
 *   1   Environment check failed
 *   2   Directory check failed
 *   3   Database connection failed
 *   4   Migration failed
 *   5   Seed failed
 *   6   Admin account creation failed
 *   7   Finalization failed
 *   99  Already installed
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
use App\Core\Env;
use App\Modules\Setup\Services\SetupService;

Env::load(__DIR__ . '/../.env');

$rootPath = realpath(__DIR__ . '/..');
$setup    = new SetupService($rootPath);

// ---------------------------------------------------------------------------
// Guard: already installed
// ---------------------------------------------------------------------------
if ($setup->isAlreadyInstalled()) {
    out('');
    err('This application is already installed.');
    err('To reinstall, delete /storage/installed.lock and set APP_INSTALLED=false in .env');
    exit(99);
}

// ---------------------------------------------------------------------------
// Header
// ---------------------------------------------------------------------------
$appName = Env::get('APP_NAME', 'Application');

out('');
out('╔══════════════════════════════════════════════╗');
out("║   {$appName} — Installation Wizard" . str_repeat(' ', max(0, 44 - strlen($appName) - 26)) . '║');
out('╚══════════════════════════════════════════════╝');
out('');

// ---------------------------------------------------------------------------
// Phase 1 — Environment check
// ---------------------------------------------------------------------------
phase('1/8', 'Checking environment requirements');

$envResult = $setup->checkEnvironment();
foreach ($envResult['checks'] as $check) {
    $icon = match ($check['status']) {
        'pass'     => '  [✓]',
        'fail'     => '  [✗]',
        'optional' => '  [~]',
        default    => '  [ ]',
    };
    $detail = $check['detail'] ? " ({$check['detail']})" : '';
    out($icon . ' ' . $check['label'] . $detail);
    if ($check['status'] === 'fail' && $check['fix']) {
        out('      Fix: ' . $check['fix']);
    }
}

if (!$envResult['ok']) {
    out('');
    err('Environment check failed. Resolve the issues above and try again.');
    exit(1);
}
out('');

// ---------------------------------------------------------------------------
// Phase 2 — Directory check
// ---------------------------------------------------------------------------
phase('2/8', 'Checking directory permissions');

$dirResult = $setup->checkDirectories();
foreach ($dirResult['paths'] as $entry) {
    $icon = $entry['status'] === 'ok' ? '  [✓]' : '  [✗]';
    out($icon . ' ' . $entry['path']);
    if ($entry['status'] === 'error' && $entry['fix']) {
        out('      Fix: ' . $entry['fix']);
    }
}

if (!$dirResult['ok']) {
    out('');
    err('Directory check failed. Resolve the issues above and try again.');
    exit(2);
}
out('');

// ---------------------------------------------------------------------------
// Phase 3 — Collect user input
// ---------------------------------------------------------------------------
phase('3/8', 'Gathering installation settings');
out('  Press Enter to accept the default shown in [brackets].');
out('');

// App URL
$currentUrl = Env::get('APP_URL', 'http://localhost');
$appUrl     = prompt("  Application URL [{$currentUrl}]: ");
if ($appUrl === '') {
    $appUrl = $currentUrl;
}
$appUrl = rtrim($appUrl, '/');

// Database driver
out('');
out('  Database driver:');
out('    [1] SQLite  (default — no server required)');
out('    [2] MySQL / MariaDB  (not yet available)');
$driverChoice = '';
while (!in_array($driverChoice, ['1', '2', ''], true)) {
    $driverChoice = prompt('  Choose driver [1]: ');
    if ($driverChoice === '') {
        $driverChoice = '1';
    }
    if ($driverChoice === '2') {
        out('');
        out('  MySQL / MariaDB support is planned but not yet available.');
        out('  Please choose option 1 (SQLite) for now.');
        out('');
        $driverChoice = '';
    }
}
$driver = 'sqlite'; // only sqlite is available right now

// Admin account
out('');
out('  Administrator account:');
$adminName     = '';
$adminUsername = '';
$adminEmail    = '';
$adminPassword = '';
$adminConfirm  = '';

while ($adminName === '') {
    $adminName = trim(prompt('  Full name: '));
    if ($adminName === '') {
        out('  Full name is required.');
    }
}

while ($adminUsername === '') {
    $adminUsername = trim(prompt('  Username: '));
    if ($adminUsername === '') {
        out('  Username is required.');
    } elseif (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $adminUsername)) {
        out('  Username must be 3–50 characters (letters, numbers, underscores).');
        $adminUsername = '';
    }
}

while ($adminEmail === '') {
    $adminEmail = trim(prompt('  Email address: '));
    if ($adminEmail === '' || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)) {
        out('  Please enter a valid email address.');
        $adminEmail = '';
    }
}

while (true) {
    $adminPassword = promptPassword('  Password (min 8 characters): ');
    if (strlen($adminPassword) < 8) {
        out('  Password must be at least 8 characters.');
        continue;
    }
    $adminConfirm = promptPassword('  Confirm password: ');
    if ($adminPassword !== $adminConfirm) {
        out('  Passwords do not match. Please try again.');
        continue;
    }
    break;
}

// ---------------------------------------------------------------------------
// Confirmation
// ---------------------------------------------------------------------------
out('');
out('  ┌─ Installation summary ───────────────────────┐');
out('  │  App URL   : ' . str_pad($appUrl, 33) . '│');
out('  │  Database  : ' . str_pad(ucfirst($driver), 33) . '│');
out('  │  Admin     : ' . str_pad($adminUsername . ' <' . $adminEmail . '>', 33) . '│');
out('  └──────────────────────────────────────────────┘');
out('');

$confirm = prompt('  Proceed with installation? [Y/n]: ');
if (!in_array(strtolower($confirm), ['', 'y', 'yes'], true)) {
    out('  Installation cancelled.');
    exit(0);
}
out('');

// ---------------------------------------------------------------------------
// Phase 4 — Write configuration
// ---------------------------------------------------------------------------
phase('4/8', 'Writing configuration');

try {
    // Update .env: only APP_URL (APP_INSTALLED is set at finalization)
    $setup->writeEnvSettings(['APP_URL' => $appUrl]);
    out('  [✓] Updated .env');

    // Write config/local.php with database driver
    $localConfig = ['database' => ['driver' => $driver]];
    $setup->writeLocalConfig($localConfig);
    out('  [✓] Wrote ' . $setup->getLocalConfigPath());
} catch (\Throwable $e) {
    out('  [✗] Config write failed: ' . $e->getMessage());
    exit(3);
}
out('');

// ---------------------------------------------------------------------------
// Phase 5 — Connect to database
// ---------------------------------------------------------------------------
phase('5/8', 'Connecting to database');

$connResult = $setup->testConnection($driver, []);
if (!$connResult['ok']) {
    out('  [✗] ' . $connResult['error']);
    err('Database connection failed.');
    exit(3);
}
out('  [✓] Connection successful (' . ucfirst($driver) . ')');
out('');

// Open the live connection for remaining phases
try {
    $db = $setup->buildDatabase($driver);
} catch (\Throwable $e) {
    err('Failed to open database: ' . $e->getMessage());
    exit(3);
}

// ---------------------------------------------------------------------------
// Phase 6 — Migrations
// ---------------------------------------------------------------------------
phase('6/8', 'Running database migrations');

$migResult = $setup->runMigrations($db);
if (!$migResult['ok']) {
    out('  [✗] ' . $migResult['error']);
    err('Migration failed.');
    exit(4);
}

if (empty($migResult['applied'])) {
    out('  [✓] No pending migrations.');
} else {
    foreach ($migResult['applied'] as $name) {
        out('  [✓] Applied: ' . $name);
    }
}
out('');

// ---------------------------------------------------------------------------
// Phase 7 — Seeds
// ---------------------------------------------------------------------------
phase('7/8', 'Running database seeds');

$seedResult = $setup->runSeeds($db, ['AdminBootstrap']);
if (!$seedResult['ok']) {
    out('  [✗] ' . $seedResult['error']);
    err('Seed failed.');
    exit(5);
}

foreach ($seedResult['log'] as $line) {
    out('  ' . $line);
}
out('  [✓] Seeds complete.');
out('');

// ---------------------------------------------------------------------------
// Phase 8 — Admin user + finalize
// ---------------------------------------------------------------------------
phase('8/8', 'Creating administrator account and finalizing');

$adminResult = $setup->createAdminUser($db, [
    'display_name'    => $adminName,
    'username'        => $adminUsername,
    'email'           => $adminEmail,
    'password'        => $adminPassword,
    'password_confirm'=> $adminConfirm,
]);

if (!$adminResult['ok']) {
    if (!empty($adminResult['errors'])) {
        foreach ($adminResult['errors'] as $field => $msg) {
            out("  [✗] {$field}: {$msg}");
        }
    } else {
        out('  [✗] ' . $adminResult['error']);
    }
    err('Admin account creation failed.');
    exit(6);
}
out("  [✓] Administrator account created (ID: {$adminResult['user_id']})");

// Finalize — write lock + APP_INSTALLED=true
try {
    $setup->finalize();
    out('  [✓] Installation lock written');
    out('  [✓] APP_INSTALLED set to true');
} catch (\Throwable $e) {
    out('  [✗] ' . $e->getMessage());
    err('Finalization failed.');
    exit(7);
}

// ---------------------------------------------------------------------------
// Done
// ---------------------------------------------------------------------------
out('');
out('╔══════════════════════════════════════════════╗');
out('║   Installation complete!                     ║');
out('╚══════════════════════════════════════════════╝');
out('');
out("  Application URL : {$appUrl}");
out("  Login URL       : {$appUrl}/auth/login");
out("  Admin username  : {$adminUsername}");
out('');

exit(0);

// ===========================================================================
// I/O helpers
// ===========================================================================

function out(string $line): void
{
    echo $line . PHP_EOL;
}

function err(string $line): void
{
    fwrite(STDERR, 'ERROR: ' . $line . PHP_EOL);
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

function promptPassword(string $label): string
{
    echo $label;

    // Attempt to disable terminal echo on Unix systems
    $echoOff = false;
    if (PHP_OS_FAMILY !== 'Windows' && function_exists('shell_exec')) {
        @shell_exec('stty -echo 2>/dev/null');
        $echoOff = true;
    }

    $value = fgets(STDIN);

    if ($echoOff) {
        @shell_exec('stty echo 2>/dev/null');
        echo PHP_EOL;
    }

    return $value === false ? '' : rtrim($value, "\r\n");
}
