<?php

/**
 * NetMon — Retention Cleanup
 *
 * Deletes historical monitoring and notification rows that are older than the
 * configured retention thresholds. Safe to run repeatedly (idempotent): a row
 * already deleted is simply not matched on subsequent runs.
 *
 * Retention thresholds are read from config/monitoring.php (merged with any
 * overrides in config/local.php under the 'monitoring' key):
 *
 *   monitoring.retention.device_checks_days        (default: 30)
 *   monitoring.retention.service_checks_days       (default: 30)
 *   monitoring.retention.notification_history_days (default: 90)
 *
 * Tables cleaned:
 *   - device_checks          (cutoff: checked_at)
 *   - service_checks         (cutoff: checked_at)
 *   - notification_history   (cutoff: created_at)
 *
 * Tables never touched:
 *   - alerts
 *   - devices
 *   - monitored_services
 *   - (all other tables)
 *
 * Usage:
 *   php scripts/cleanup.php
 *
 * Schedule with cron (recommended: once daily):
 *   0 3 * * * php /path/to/netmon/scripts/cleanup.php >> /path/to/netmon/storage/logs/cleanup.log 2>&1
 *
 * Exit codes:
 *   0 — success
 *   1 — fatal error (database unavailable, unsupported driver, etc.)
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
// Bootstrap
// ---------------------------------------------------------------------------
use App\Core\Config;
use App\Core\Env;
use App\Core\SQLiteDriver;
use App\Models\DeviceCheckRepository;
use App\Models\NotificationRepository;
use App\Models\ServiceCheckRepository;

Env::load(__DIR__ . '/../.env');

$dbConfig = Config::load('database');

if ($dbConfig['driver'] === 'sqlite') {
    $db = new SQLiteDriver($dbConfig['sqlite']['path']);
} else {
    fwrite(STDERR, "Unsupported database driver: {$dbConfig['driver']}\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Retention config
// ---------------------------------------------------------------------------
$monitoringConfig = Config::load('monitoring');
$retention        = $monitoringConfig['retention'] ?? [];

$deviceChecksDays        = (int) ($retention['device_checks_days']        ?? 30);
$serviceChecksDays       = (int) ($retention['service_checks_days']       ?? 30);
$notificationHistoryDays = (int) ($retention['notification_history_days'] ?? 90);

// Guard against nonsensical values that could wipe all data.
if ($deviceChecksDays < 1 || $serviceChecksDays < 1 || $notificationHistoryDays < 1) {
    fwrite(STDERR, "Retention days must be >= 1. Check your monitoring.retention config.\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Compute cutoff timestamps
// ---------------------------------------------------------------------------
$now = new \DateTime('now');

$deviceCutoff  = (clone $now)->modify("-{$deviceChecksDays} days");
$serviceCutoff = (clone $now)->modify("-{$serviceChecksDays} days");
$notifCutoff   = (clone $now)->modify("-{$notificationHistoryDays} days");

// ---------------------------------------------------------------------------
// Run cleanup
// ---------------------------------------------------------------------------
$deviceRepo  = new DeviceCheckRepository($db);
$serviceRepo = new ServiceCheckRepository($db);
$notifRepo   = new NotificationRepository($db);

try {
    $deletedDeviceChecks  = $deviceRepo->deleteOlderThan($deviceCutoff);
    $deletedServiceChecks = $serviceRepo->deleteOlderThan($serviceCutoff);
    $deletedNotifications = $notifRepo->deleteOlderThan($notifCutoff);
} catch (\Throwable $e) {
    fwrite(STDERR, "Cleanup failed: " . $e->getMessage() . "\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n── Cleanup Summary\n";
echo "Device checks deleted:  {$deletedDeviceChecks}\n";
echo "Service checks deleted: {$deletedServiceChecks}\n";
echo "Notifications deleted:  {$deletedNotifications}\n";

exit(0);
