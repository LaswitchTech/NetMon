<?php

/**
 * NetMon — Notification Queue Worker
 *
 * Processes the module_notification_queue table, delivering pending
 * notifications through the appropriate channel (in_app, email, etc.).
 *
 * This script is designed to be invoked from cron, typically every minute:
 *   * * * * * php /path/to/scripts/notify.php >> /path/to/storage/logs/notify.log 2>&1
 *
 * Each invocation fetches all queue items with available_at <= now and
 * attempts < max_attempts, then delivers them via the registered channel.
 *
 * Retry policy:
 *   - sent / skipped          → queue row deleted; delivery already updated by channel
 *   - failed + retryable      → available_at pushed forward by (new_attempts × 60s)
 *                               e.g. 1st retry after 60s, 2nd retry after 120s
 *   - failed + exhausted      → queue row deleted; delivery row already marked failed
 *
 * Usage:
 *   php scripts/notify.php            # process all due queue items
 *   php scripts/notify.php --verbose  # show per-item detail
 *   php scripts/notify.php --dry-run  # fetch due items but do not deliver or mutate
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
use App\Models\UserRepository;
use App\Modules\Notifications\Models\NotificationRepository as ModuleNotificationRepository;
use App\Modules\Notifications\Models\NotificationQueueRepository;
use App\Modules\Notifications\Services\Channels\EmailChannel;
use App\Modules\Notifications\Services\Channels\InAppChannel;

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
// Repositories
// ---------------------------------------------------------------------------
$moduleNotifRepo = new ModuleNotificationRepository($db);
$queueRepo       = new NotificationQueueRepository($db);
$userRepo        = new UserRepository($db);

// ---------------------------------------------------------------------------
// Channel registry
// ---------------------------------------------------------------------------
// Channels are keyed by their name() value.  Only channels registered here
// will be processed.  Unknown channel names in the queue are skipped.
// ---------------------------------------------------------------------------
$notifModuleConfig = Config::load('notifications-module');

$channelRegistry = [];

$inAppChannel = new InAppChannel($moduleNotifRepo);
$channelRegistry[$inAppChannel->name()] = $inAppChannel;

$emailChannel = new EmailChannel($moduleNotifRepo, $notifModuleConfig['email'] ?? []);
$channelRegistry[$emailChannel->name()] = $emailChannel;

// ---------------------------------------------------------------------------
// Run
// ---------------------------------------------------------------------------
$now       = date('Y-m-d H:i:s');
$timestamp = $now;

echo "NetMon Notify — {$timestamp}\n";
echo str_repeat('-', 50) . "\n";

if ($dryRun) {
    echo "[DRY RUN] No deliveries will be attempted or queue rows mutated.\n";
}

$dueItems = $queueRepo->fetchDue($now);
$total    = count($dueItems);

if ($total === 0) {
    echo "No items due. Queue is empty or all items are scheduled for later.\n";
    exit(0);
}

echo "Processing {$total} queue item(s)...\n\n";

$countSent     = 0;
$countSkipped  = 0;
$countFailed   = 0;
$countRetried  = 0;
$countExhausted = 0;
$countUnknown  = 0;

foreach ($dueItems as $item) {
    $queueId    = (int) $item['id'];
    $deliveryId = (int) $item['delivery_id'];
    $channel    = $item['channel'];
    $attempts   = (int) $item['attempts'];
    $maxAttempts = (int) $item['max_attempts'];

    // ── Resolve the delivery + notification row ───────────────────────────
    $delivery = $moduleNotifRepo->findDeliveryById($deliveryId);

    if ($delivery === null) {
        // Delivery was deleted — queue row will be cleaned up by CASCADE DELETE.
        // Should not reach here in normal operation.
        if ($verbose) {
            echo "  [SKIP]    Queue #{$queueId} ch={$channel} → delivery #{$deliveryId} not found (already deleted)\n";
        }
        $countSkipped++;
        continue;
    }

    // ── Resolve the recipient user ────────────────────────────────────────
    $user = $userRepo->findById((int) $delivery['user_id']);

    if ($user === null) {
        // User was deleted — skip and remove the queue item.
        if ($verbose) {
            echo "  [SKIP]    Queue #{$queueId} ch={$channel} → user #{$delivery['user_id']} not found\n";
        }
        if (!$dryRun) {
            $queueRepo->delete($queueId);
        }
        $countSkipped++;
        continue;
    }

    // ── Look up the channel handler ───────────────────────────────────────
    if (!isset($channelRegistry[$channel])) {
        // Unknown channel — silently skip and remove.
        if ($verbose) {
            echo "  [SKIP]    Queue #{$queueId} ch={$channel} → channel not registered\n";
        }
        if (!$dryRun) {
            $queueRepo->delete($queueId);
        }
        $countUnknown++;
        continue;
    }

    // ── Dry-run: report only ──────────────────────────────────────────────
    if ($dryRun) {
        $title = $delivery['title'] ?? '(no title)';
        echo "  [DRY]     Queue #{$queueId} ch={$channel} delivery=#{$deliveryId} user=#{$user['id']} \"{$title}\"\n";
        continue;
    }

    // ── Build notification array for the channel ──────────────────────────
    $notification = [
        'id'          => (int) $delivery['notification_id'],
        'source_type' => $delivery['source_type'] ?? null,
        'source_id'   => $delivery['source_id']   ?? null,
        'title'       => $delivery['title'],
        'body'        => $delivery['body'],
        'data'        => $delivery['data']         ?? null,
        'created_at'  => $delivery['created_at'],
    ];

    // ── Deliver ───────────────────────────────────────────────────────────
    $result = $channelRegistry[$channel]->deliver($notification, $user, $delivery);
    $status = $result['status'];  // 'sent' | 'skipped' | 'failed'

    if ($verbose) {
        $icon  = $status === 'sent' ? '✓' : ($status === 'skipped' ? '~' : '✗');
        $title = $delivery['title'] ?? '(no title)';
        $extra = $result['error'] ? " ({$result['error']})" : '';
        echo "  [{$icon}]       Queue #{$queueId} ch={$channel} delivery=#{$deliveryId} user=#{$user['id']} → {$status}{$extra}\n";
    }

    if ($status === 'sent' || $status === 'skipped') {
        // Delivery complete (or intentionally skipped) — remove from queue.
        $queueRepo->delete($queueId);

        if ($status === 'sent') {
            $countSent++;
        } else {
            $countSkipped++;
        }
    } else {
        // Delivery failed — decide whether to retry or abandon.
        $newAttempts = $attempts + 1;

        if ($newAttempts < $maxAttempts) {
            // Reschedule: back-off delay = new_attempts × 60 seconds.
            $delaySecs   = $newAttempts * 60;
            $availableAt = date('Y-m-d H:i:s', time() + $delaySecs);
            $queueRepo->reschedule($queueId, $newAttempts, $availableAt);

            if ($verbose) {
                echo "           ↳ retry #{$newAttempts} scheduled in {$delaySecs}s (at {$availableAt})\n";
            }
            $countRetried++;
        } else {
            // All attempts exhausted — remove from queue.
            // The delivery row has already been marked 'failed' by the channel.
            $queueRepo->delete($queueId);

            if ($verbose) {
                echo "           ↳ exhausted ({$newAttempts}/{$maxAttempts} attempts) — abandoned\n";
            }
            $countExhausted++;
        }

        $countFailed++;
    }
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
echo "\n" . str_repeat('-', 50) . "\n";

if ($dryRun) {
    echo "Dry run complete. {$total} item(s) would be processed.\n";
} else {
    $parts = [];
    if ($countSent      > 0) $parts[] = "{$countSent} sent";
    if ($countSkipped   > 0) $parts[] = "{$countSkipped} skipped";
    if ($countRetried   > 0) $parts[] = "{$countRetried} retried";
    if ($countExhausted > 0) $parts[] = "{$countExhausted} exhausted";
    if ($countUnknown   > 0) $parts[] = "{$countUnknown} unknown channel";

    $summary = empty($parts) ? "nothing to report" : implode(', ', $parts);
    echo "Done. {$summary}.\n";
}
