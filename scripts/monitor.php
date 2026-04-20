<?php

/**
 * NetMon — Monitoring Runner (Phase 15)
 *
 * Performs one pass of:
 *   1. Device-level ICMP reachability checks (ping all active devices)
 *   2. Service-level TCP connectivity checks (TCP-connect all enabled services)
 *
 * Device pass: pings every active device, stores results in device_checks,
 * updates devices.status, manages stateful alerts, dispatches log/webhook
 * notifications with 15-minute throttling, and enqueues in-app + email
 * notifications via the reusable Notifications module on first-open and
 * resolved events.
 *
 * Service pass: TCP-connects every enabled service on active devices, stores
 * results in service_checks, updates monitored_services.last_state, manages
 * stateful alerts, and enqueues in-app + email notifications on first-open
 * and resolved events.
 *
 * Usage:
 *   php scripts/monitor.php            # run one monitoring pass
 *   php scripts/monitor.php --verbose  # show per-device/service detail
 *   php scripts/monitor.php --dry-run  # resolve targets — no DB writes, no notifications sent
 *
 * Schedule with cron for continuous monitoring:
 *   * * * * * php /path/to/scripts/monitor.php >> /path/to/storage/logs/monitor.log 2>&1
 *
 * Module notification dispatch policy (in-app + email):
 *   - type='open'     → enqueued to ['in_app', 'email'] (alert first created)
 *   - type='reminder' → skipped (re-confirmation — no inbox/email flood)
 *   - resolved        → enqueued to ['in_app', 'email'] (condition cleared)
 *   Recipients: all active users (is_active = 1).
 *   Actual delivery is handled by the worker (scripts/notify.php), which
 *   processes the module_notification_queue table asynchronously.
 *
 * Limitations:
 *   - Service checks: TCP only. No UDP, HTTP, or ICMP service checks yet.
 *   - No daemon mode — one pass per invocation.
 *   - Device checks use exec(ping). If exec() is disabled, checks are recorded
 *     as 'error' and device status/alerts are not updated.
 *   - Service checks use fsockopen() — no exec() required.
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
use App\Models\ServiceCheckRepository;
use App\Models\UserRepository;
use App\Monitoring\Pinger;
use App\Monitoring\TcpChecker;
use App\Modules\Notifications\Models\NotificationRepository as ModuleNotificationRepository;
use App\Modules\Notifications\Models\NotificationQueueRepository;
use App\Modules\Notifications\Models\NotificationPreferenceRepository;
use App\Modules\Notifications\Services\NotificationService;
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
$repo        = new DeviceCheckRepository($db);
$alertRepo   = new AlertRepository($db);
$notifRepo   = new NotificationRepository($db);
$serviceRepo = new ServiceCheckRepository($db);
$pinger      = new Pinger();
$tcpChecker  = new TcpChecker();

// ---------------------------------------------------------------------------
// Notifications module — async queue dispatch
//
// NetMon-specific event mapping (title/body/source context) lives here, in
// NetMon-side code.  The NotificationService and its channels know nothing
// about devices, alerts, or any NetMon class — the module stays reusable.
//
// dispatch() enqueues delivery items; the worker (scripts/notify.php)
// processes the queue asynchronously, decoupling SMTP latency and transient
// channel failures from the monitoring runner's hot path.
//
// Recipient rule:
//   All active users are candidates for each alert event.
//   NotificationService filters per-user per-channel using notification_preferences.
//   Default when no preference row exists: channel enabled (opt-out model).
//
// Dispatch policy:
//   type='open'     → enqueue to ['in_app', 'email']
//   type='reminder' → skip (alert re-confirmed — no inbox/email spam)
//   resolved        → enqueue to ['in_app', 'email']
// ---------------------------------------------------------------------------
$moduleNotifRepo = new ModuleNotificationRepository($db);
$queueRepo       = new NotificationQueueRepository($db);
$prefRepo        = new NotificationPreferenceRepository($db);
$notifService    = new NotificationService($moduleNotifRepo, $queueRepo, $prefRepo);

$userRepo        = new UserRepository($db);
$activeRecipients = $userRepo->findAllActive();

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

                // Dispatch a notification for the recovery (in-app + email).
                if (!$dryRun && !empty($activeRecipients)) {
                    $resolvedAlertId = (int) $openAlert['id'];

                    $notifService->dispatch(
                        [
                            'source_type' => 'alert',
                            'source_id'   => $resolvedAlertId,
                            'title'       => "Device recovered: {$name}",
                            'body'        => "{$name} ({$targetAddress}) is back online.",
                            'data'        => [
                                'alert_id'  => $resolvedAlertId,
                                'device_id' => $id,
                            ],
                        ],
                        $activeRecipients,
                        ['in_app', 'email']
                    );

                    if ($verbose) {
                        echo "           ↳ notification enqueued for " . count($activeRecipients) . " user(s) [in_app, email]\n";
                    }
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
                    $chanResult = $channel->send($notifyType, $notifyAlert, $device);

                    $notifRepo->record([
                        'alert_id'          => $notifyAlertId,
                        'channel'           => $channel->name(),
                        'recipient'         => $channel->recipient(),
                        'notification_type' => $notifyType,
                        'status'            => $chanResult['status'],
                        'message'           => $chanResult['message'],
                        'sent_at'           => $timestamp,
                    ]);

                    if ($verbose) {
                        $icon = $chanResult['status'] === 'sent' ? '✓' : '✗';
                        echo "           ↳ notify [{$channel->name()}] {$icon} {$notifyType}";
                        if ($chanResult['message'] !== null) {
                            echo " ({$chanResult['message']})";
                        }
                        echo "\n";
                    }
                }

                // Stamp the alert so the throttle window resets.
                $alertRepo->updateAlert($notifyAlertId, ['last_notified_at' => $timestamp]);
            }
        }

        // ---- Module notification (in-app + email) -----------------------
        // Only dispatch on 'open' (first alert creation), never on reminders.
        // Resolution notifications are dispatched at the resolve site above.
        if ($pendingNotification !== null
            && $pendingNotification['type'] === 'open'
            && !$dryRun
            && !empty($activeRecipients)
        ) {
            $notifyAlertId = (int) $pendingNotification['alert']['id'];

            $notifService->dispatch(
                [
                    'source_type' => 'alert',
                    'source_id'   => $notifyAlertId,
                    'title'       => "Device offline: {$name}",
                    'body'        => "{$name} ({$targetAddress}) failed its reachability check.",
                    'data'        => [
                        'alert_id'  => $notifyAlertId,
                        'device_id' => $id,
                    ],
                ],
                $activeRecipients,
                ['in_app', 'email']
            );

            if ($verbose) {
                echo "           ↳ notification enqueued for " . count($activeRecipients) . " user(s) [in_app, email]\n";
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
// Service checks
// ---------------------------------------------------------------------------
$serviceTargets  = $serviceRepo->findServiceTargets();
$totalServices   = count($serviceTargets);

$countSvcUp      = 0;
$countSvcDown    = 0;
$countSvcError   = 0;
$countSvcSkipped = 0;

if ($totalServices > 0) {
    echo "\nChecking {$totalServices} service(s)...\n\n";

    foreach ($serviceTargets as $service) {
        $serviceId     = (int) $service['service_id'];
        $serviceName   = $service['service_name'];
        $deviceId      = (int) $service['device_id'];
        $deviceName    = $service['device_name'];
        $targetAddress = $service['target_address'];
        $port          = (int) $service['port'];

        // Skip services whose device has no resolvable target address.
        if ($targetAddress === null || trim($targetAddress) === '') {
            $countSvcSkipped++;
            $label   = str_pad('[SKIP]', 10);
            $nameCol = str_pad("{$deviceName} / {$serviceName}", 36);
            echo "  {$label} {$nameCol} No target address — service skipped\n";
            continue;
        }

        if ($dryRun) {
            $label   = str_pad('[DRY]', 10);
            $nameCol = str_pad("{$deviceName} / {$serviceName}", 36);
            $addrCol = str_pad("{$targetAddress}:{$port}", 26);
            echo "  {$label} {$nameCol} {$addrCol} (would check)\n";
            continue;
        }

        // Perform the TCP connectivity check.
        $svcResult = $tcpChecker->check($targetAddress, $port);

        // Save check row (append-only historical log).
        $serviceRepo->saveCheck([
            'service_id' => $serviceId,
            'checked_at' => $timestamp,
            'status'     => $svcResult['status'],
            'latency_ms' => $svcResult['latency_ms'],
            'message'    => $svcResult['message'],
        ]);

        // Update state + manage alerts + dispatch notifications.
        // 'error' means the runner itself failed (e.g. no target address) —
        // preserve last_state and skip all alert/notification logic, same as device checks.
        if ($svcResult['status'] !== 'error') {
            $serviceRepo->updateServiceState($serviceId, $svcResult['status'], $timestamp);

            // ---- Alert logic ------------------------------------------------
            // Deduplication rule: there is at most one OPEN 'service_down' alert
            // per (device_id, service_id) at any time. On failure we create-or-update;
            // on recovery we resolve the open alert if one is present.
            //
            // $svcPendingNotification is set here and consumed by the notification
            // block below. It carries the alert row and the notification type
            // ('open' or 'reminder') needed for throttle evaluation and dispatch.
            $svcPendingNotification = null;

            if ($svcResult['status'] === 'down') {
                $openAlert = $alertRepo->findOpenAlert($deviceId, $serviceId, 'service_down');

                if ($openAlert !== null) {
                    // Alert already open — re-confirm: update last_seen and count.
                    $alertRepo->incrementOccurrence((int) $openAlert['id'], $timestamp);

                    // Re-fetch so occurrence_count reflects the increment we just wrote.
                    $openAlert['occurrence_count'] = (int) $openAlert['occurrence_count'] + 1;
                    $svcPendingNotification = ['type' => 'reminder', 'alert' => $openAlert];

                    if ($verbose) {
                        echo "           ↳ alert #" . $openAlert['id'] . " re-confirmed (occurrence #" . $openAlert['occurrence_count'] . ")\n";
                    }
                } else {
                    // No open alert — create one.
                    $newAlertId = $alertRepo->createAlert([
                        'device_id'     => $deviceId,
                        'service_id'    => $serviceId,
                        'alert_type'    => 'service_down',
                        'first_seen_at' => $timestamp,
                        'last_seen_at'  => $timestamp,
                    ]);

                    // Build a minimal alert array for the notification block.
                    // last_notified_at is NULL on a freshly created alert.
                    $svcPendingNotification = [
                        'type'  => 'open',
                        'alert' => [
                            'id'               => $newAlertId,
                            'alert_type'       => 'service_down',
                            'occurrence_count' => 1,
                            'first_seen_at'    => $timestamp,
                            'last_seen_at'     => $timestamp,
                            'last_notified_at' => null,
                        ],
                    ];

                    if ($verbose) {
                        echo "           ↳ alert #{$newAlertId} opened: service_down\n";
                    }
                }
            } elseif ($svcResult['status'] === 'up') {
                $openAlert = $alertRepo->findOpenAlert($deviceId, $serviceId, 'service_down');

                if ($openAlert !== null) {
                    // Service recovered — resolve the open alert.
                    $alertRepo->resolveAlert((int) $openAlert['id'], $timestamp);

                    if ($verbose) {
                        echo "           ↳ alert #" . $openAlert['id'] . " resolved (service back up)\n";
                    }

                    // Dispatch a notification for the recovery (in-app + email).
                    if (!$dryRun && !empty($activeRecipients)) {
                        $resolvedAlertId = (int) $openAlert['id'];

                        $notifService->dispatch(
                            [
                                'source_type' => 'alert',
                                'source_id'   => $resolvedAlertId,
                                'title'       => "Service restored: {$deviceName} / {$serviceName}",
                                'body'        => "{$serviceName} on {$deviceName} ({$targetAddress}:{$port}) is responding again.",
                                'data'        => [
                                    'alert_id'   => $resolvedAlertId,
                                    'device_id'  => $deviceId,
                                    'service_id' => $serviceId,
                                ],
                            ],
                            $activeRecipients,
                            ['in_app', 'email']
                        );

                        if ($verbose) {
                            echo "           ↳ notification enqueued for " . count($activeRecipients) . " user(s) [in_app, email]\n";
                        }
                    }
                }
            }

            // ---- Notification logic -----------------------------------------
            // Dispatch to enabled channels if:
            //   a) there is a pending notification from the alert block above, AND
            //   b) the throttle window has elapsed (or this is the first notification), AND
            //   c) at least one channel is configured.
            //
            // Device context is passed to channels using the same contract as device
            // alerts — channels receive (type, alert, device) where device carries
            // id, name, and target_address.
            if ($svcPendingNotification !== null && !empty($channels)) {
                $notifyType    = $svcPendingNotification['type'];
                $notifyAlert   = $svcPendingNotification['alert'];
                $notifyAlertId = (int) $notifyAlert['id'];

                $deviceContext = [
                    'id'             => $deviceId,
                    'name'           => $deviceName,
                    'target_address' => $targetAddress,
                ];

                if ($shouldNotify($notifyAlert, $throttleSeconds)) {
                    foreach ($channels as $channel) {
                        $chanResult = $channel->send($notifyType, $notifyAlert, $deviceContext);

                        $notifRepo->record([
                            'alert_id'          => $notifyAlertId,
                            'channel'           => $channel->name(),
                            'recipient'         => $channel->recipient(),
                            'notification_type' => $notifyType,
                            'status'            => $chanResult['status'],
                            'message'           => $chanResult['message'],
                            'sent_at'           => $timestamp,
                        ]);

                        if ($verbose) {
                            $icon = $chanResult['status'] === 'sent' ? '✓' : '✗';
                            echo "           ↳ notify [{$channel->name()}] {$icon} {$notifyType}";
                            if ($chanResult['message'] !== null) {
                                echo " ({$chanResult['message']})";
                            }
                            echo "\n";
                        }
                    }

                    // Stamp the alert so the throttle window resets.
                    $alertRepo->updateAlert($notifyAlertId, ['last_notified_at' => $timestamp]);
                }
            }

            // ---- Module notification (in-app + email) -----------------------
            // Only dispatch on 'open' (first alert creation), never on reminders.
            // Resolution notifications are dispatched at the resolve site above.
            if ($svcPendingNotification !== null
                && $svcPendingNotification['type'] === 'open'
                && !$dryRun
                && !empty($activeRecipients)
            ) {
                $notifyAlertId = (int) $svcPendingNotification['alert']['id'];

                $notifService->dispatch(
                    [
                        'source_type' => 'alert',
                        'source_id'   => $notifyAlertId,
                        'title'       => "Service down: {$deviceName} / {$serviceName}",
                        'body'        => "{$serviceName} on {$deviceName} ({$targetAddress}:{$port}) is not responding.",
                        'data'        => [
                            'alert_id'   => $notifyAlertId,
                            'device_id'  => $deviceId,
                            'service_id' => $serviceId,
                        ],
                    ],
                    $activeRecipients,
                    ['in_app', 'email']
                );

                if ($verbose) {
                    echo "           ↳ notification enqueued for " . count($activeRecipients) . " user(s) [in_app, email]\n";
                }
            }
            // -----------------------------------------------------------------
        }

        // Track counts.
        match ($svcResult['status']) {
            'up'    => $countSvcUp++,
            'error' => $countSvcError++,
            default => $countSvcDown++,
        };

        // Format output line.
        $label = match ($svcResult['status']) {
            'up'    => str_pad('[UP]',    10),
            'down'  => str_pad('[DOWN]',  10),
            'error' => str_pad('[ERROR]', 10),
            default => str_pad('[?]',     10),
        };

        $nameCol    = str_pad("{$deviceName} / {$serviceName}", 36);
        $addrCol    = str_pad("{$targetAddress}:{$port}", 26);
        $latencyStr = $svcResult['latency_ms'] !== null
            ? $svcResult['latency_ms'] . ' ms'
            : '—';

        $line = "  {$label} {$nameCol} {$addrCol} {$latencyStr}";

        if ($verbose && $svcResult['message'] !== null) {
            $line .= "  ({$svcResult['message']})";
        }

        echo $line . "\n";
    }
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

    if ($totalServices > 0) {
        $svcParts = [];
        if ($countSvcUp      > 0) $svcParts[] = "{$countSvcUp} up";
        if ($countSvcDown    > 0) $svcParts[] = "{$countSvcDown} down";
        if ($countSvcError   > 0) $svcParts[] = "{$countSvcError} error";
        if ($countSvcSkipped > 0) $svcParts[] = "{$countSvcSkipped} skipped";
        if (!empty($svcParts)) {
            echo "Services: " . implode(', ', $svcParts) . ".\n";
        }
    }
}

exit(0);
