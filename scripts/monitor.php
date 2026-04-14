<?php

/**
 * NetMon — Device Monitoring Runner (Phase 7)
 *
 * Performs one pass of device-level reachability checks: pings every active
 * device, stores results in device_checks, updates devices.status, manages
 * stateful alerts, and dispatches notifications with 15-minute throttling.
 *
 * Usage:
 *   php scripts/monitor.php            # run one monitoring pass
 *   php scripts/monitor.php --verbose  # show per-device detail including alert and notification events
 *   php scripts/monitor.php --dry-run  # resolve targets — no DB writes, no notifications sent
 *
 * Schedule with cron for continuous monitoring:
 *   * * * * * php /path/to/scripts/monitor.php >> /path/to/storage/logs/monitor.log 2>&1
 *
 * Limitations (Phase 7):
 *   - Device-level (ICMP) checks only.
 *   - Notifications for device_offline alerts only (open + reminder).
 *   - No service/port checks yet.
 *   - No daemon mode — one pass per invocation.
 *   - Uses exec(ping). If exec() is disabled, checks are recorded as 'error'
 *     and neither device status, alerts, nor notifications are updated.
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
use App\Models\AlertRepository;
use App\Models\DeviceCheckRepository;
use App\Models\NotificationRepository;
use App\Monitoring\Pinger;
use App\Notifications\LogChannel;
use App\Notifications\WebhookChannel;

Env::load(__DIR__ . '/../.env');

$dbConfig = Config::load('database');

if ($dbConfig['driver'] === 'sqlite') {
    $db = new SQLiteDriver($dbConfig['sqlite']['path']);
} else {
    fwrite(STDERR, "Unsupported database driver: {$dbConfig['driver']}\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Parse flags
// ---------------------------------------------------------------------------
$args    = array_slice($argv, 1);
$verbose = in_array('--verbose', $args, true) || in_array('-v', $args, true);
$dryRun  = in_array('--dry-run', $args, true);

// ---------------------------------------------------------------------------
// Run
// ---------------------------------------------------------------------------
$repo       = new DeviceCheckRepository($db);
$alertRepo  = new AlertRepository($db);
$notifRepo  = new NotificationRepository($db);
$pinger     = new Pinger();

// ---------------------------------------------------------------------------
// Notification channels
// ---------------------------------------------------------------------------
$notifConfig     = Config::load('notifications');
$throttleSeconds = (int) ($notifConfig['throttle_seconds'] ?? 900);

/** @var \App\Notifications\ChannelInterface[] $channels */
$channels = [];

if (!empty($notifConfig['channels']['log']['enabled'])) {
    $channels[] = new LogChannel($notifConfig['channels']['log']['path']);
}

if (!empty($notifConfig['channels']['webhook']['enabled'])) {
    $channels[] = new WebhookChannel(
        $notifConfig['channels']['webhook']['url']    ?? '',
        $notifConfig['channels']['webhook']['timeout'] ?? 5
    );
}

// ---------------------------------------------------------------------------
// Throttle helper
// ---------------------------------------------------------------------------
/**
 * Returns true if a notification should be sent for the given alert.
 * Sends immediately when last_notified_at is NULL (first notification).
 * Sends again only after $throttleSeconds have elapsed since the last send.
 */
$shouldNotify = static function (array $alert, int $throttleSeconds): bool {
    if ($alert['last_notified_at'] === null) {
        return true;
    }
    return (time() - strtotime($alert['last_notified_at'])) >= $throttleSeconds;
};

$passStart = microtime(true);
$timestamp = date('Y-m-d H:i:s');

$targets = $repo->findMonitoringTargets();
$total   = count($targets);

echo "NetMon Monitor — {$timestamp}\n";
echo str_repeat('-', 50) . "\n";

if ($dryRun) {
    echo "[DRY RUN] No checks will be performed or saved.\n";
}

if ($total === 0) {
    echo "No active devices to monitor.\n";
    exit(0);
}

echo "Checking {$total} device(s)...\n\n";

$countOnline  = 0;
$countOffline = 0;
$countError   = 0;
$countSkipped = 0;

foreach ($targets as $device) {
    $id            = (int) $device['id'];
    $name          = $device['name'];
    $targetAddress = $device['target_address'];

    // Skip devices with no resolvable target address
    if ($targetAddress === null || trim($targetAddress) === '') {
        $countSkipped++;
        $label = str_pad('[SKIP]', 10);
        $nameCol = str_pad($name, 28);
        echo "  {$label} {$nameCol} No target address — device skipped\n";
        continue;
    }

    if ($dryRun) {
        $label   = str_pad('[DRY]', 10);
        $nameCol = str_pad($name, 28);
        $addrCol = str_pad($targetAddress, 20);
        echo "  {$label} {$nameCol} {$addrCol} (would check)\n";
        continue;
    }

    // Perform the reachability check
    $result = $pinger->check($targetAddress);

    // Save check row (historical, append-only)
    $repo->saveCheck([
        'device_id'  => $id,
        'checked_at' => $timestamp,
        'status'     => $result['status'],
        'latency_ms' => $result['latency_ms'],
        'message'    => $result['message'],
    ]);

    // Update current device status summary.
    // 'error' means the runner itself could not execute the check (e.g. exec() disabled),
    // not that the device is unreachable — so we leave devices.status and alerts unchanged.
    if ($result['status'] !== 'error') {
        $deviceStatus = $result['status'] === 'online' ? 'online' : 'offline';
        $repo->updateDeviceStatus($id, $deviceStatus, $timestamp);

        // ---- Alert logic ------------------------------------------------
        // Deduplication rule: there is at most one OPEN 'device_offline' alert
        // per device at any time. On failure we create-or-update; on recovery
        // we resolve the open alert if present.
        //
        // $pendingNotification is set here and consumed by the notification
        // block below. It carries the alert row and the notification type
        // ('open' or 'reminder') needed for throttle evaluation and dispatch.
        $pendingNotification = null;

        if ($deviceStatus === 'offline') {
            $openAlert = $alertRepo->findOpenAlert($id, null, 'device_offline');

            if ($openAlert !== null) {
                // Alert already open — re-confirm: update last_seen and count.
                $alertRepo->incrementOccurrence((int) $openAlert['id'], $timestamp);

                // Re-fetch so occurrence_count reflects the increment we just wrote.
                $openAlert['occurrence_count'] = (int) $openAlert['occurrence_count'] + 1;
                $pendingNotification = ['type' => 'reminder', 'alert' => $openAlert];

                if ($verbose) {
                    echo "           ↳ alert #" . $openAlert['id'] . " re-confirmed (occurrence #" . $openAlert['occurrence_count'] . ")\n";
                }
            } else {
                // No open alert — create one.
                $newAlertId = $alertRepo->createAlert([
                    'device_id'     => $id,
                    'service_id'    => null,
                    'alert_type'    => 'device_offline',
                    'first_seen_at' => $timestamp,
                    'last_seen_at'  => $timestamp,
                ]);

                // Build a minimal alert array for the notification block.
                // last_notified_at is NULL on a freshly created alert.
                $pendingNotification = [
                    'type'  => 'open',
                    'alert' => [
                        'id'               => $newAlertId,
                        'alert_type'       => 'device_offline',
                        'occurrence_count' => 1,
                        'first_seen_at'    => $timestamp,
                        'last_seen_at'     => $timestamp,
                        'last_notified_at' => null,
                    ],
                ];

                if ($verbose) {
                    echo "           ↳ alert #{$newAlertId} opened: device_offline\n";
                }
            }
        } elseif ($deviceStatus === 'online') {
            $openAlert = $alertRepo->findOpenAlert($id, null, 'device_offline');

            if ($openAlert !== null) {
                // Device recovered — resolve the open alert.
                $alertRepo->resolveAlert((int) $openAlert['id'], $timestamp);

                if ($verbose) {
                    echo "           ↳ alert #" . $openAlert['id'] . " resolved (device back online)\n";
                }
            }
        }

        // ---- Notification logic -----------------------------------------
        // Dispatch to enabled channels if:
        //   a) there is a pending notification from the alert block above, AND
        //   b) the throttle window has elapsed (or this is the first notification), AND
        //   c) at least one channel is configured.
        //
        // Dry-run skips all sends and history writes.
        if ($pendingNotification !== null && !$dryRun && !empty($channels)) {
            $notifyType  = $pendingNotification['type'];
            $notifyAlert = $pendingNotification['alert'];
            $notifyAlertId = (int) $notifyAlert['id'];

            if ($shouldNotify($notifyAlert, $throttleSeconds)) {
                foreach ($channels as $channel) {
                    $result = $channel->send($notifyType, $notifyAlert, $device);

                    $notifRepo->record([
                        'alert_id'          => $notifyAlertId,
                        'channel'           => $channel->name(),
                        'recipient'         => $channel->recipient(),
                        'notification_type' => $notifyType,
                        'status'            => $result['status'],
                        'message'           => $result['message'],
                        'sent_at'           => $timestamp,
                    ]);

                    if ($verbose) {
                        $icon = $result['status'] === 'sent' ? '✓' : '✗';
                        echo "           ↳ notify [{$channel->name()}] {$icon} {$notifyType}";
                        if ($result['message'] !== null) {
                            echo " ({$result['message']})";
                        }
                        echo "\n";
                    }
                }

                // Stamp the alert so the throttle window resets.
                $alertRepo->updateAlert($notifyAlertId, ['last_notified_at' => $timestamp]);
            }
        }
        // -----------------------------------------------------------------
    }

    // Track counts
    match ($result['status']) {
        'online'  => $countOnline++,
        'error'   => $countError++,
        default   => $countOffline++,
    };

    // Format output line
    $label   = match ($result['status']) {
        'online'  => str_pad('[OK]',      10),
        'offline' => str_pad('[OFFLINE]', 10),
        'timeout' => str_pad('[TIMEOUT]', 10),
        'error'   => str_pad('[ERROR]',   10),
        default   => str_pad('[?]',       10),
    };

    $nameCol    = str_pad($name, 28);
    $addrCol    = str_pad($targetAddress, 20);
    $latencyStr = $result['latency_ms'] !== null
        ? $result['latency_ms'] . ' ms'
        : '—';

    $line = "  {$label} {$nameCol} {$addrCol} {$latencyStr}";

    if ($verbose && $result['message'] !== null) {
        $line .= "  ({$result['message']})";
    }

    echo $line . "\n";
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
if (!$dryRun) {
    $elapsed = round(microtime(true) - $passStart, 2);

    echo "\n" . str_repeat('-', 50) . "\n";

    $parts = [];
    if ($countOnline  > 0) $parts[] = "{$countOnline} online";
    if ($countOffline > 0) $parts[] = "{$countOffline} offline";
    if ($countError   > 0) $parts[] = "{$countError} error";
    if ($countSkipped > 0) $parts[] = "{$countSkipped} skipped";

    echo "Done. " . implode(', ', $parts) . ". ({$elapsed}s)\n";
}

exit(0);
