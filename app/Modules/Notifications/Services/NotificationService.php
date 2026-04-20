<?php

namespace App\Modules\Notifications\Services;

use App\Modules\Notifications\Models\NotificationRepository;
use App\Modules\Notifications\Models\NotificationQueueRepository;
use App\Modules\Notifications\Models\NotificationPreferenceRepository;

/**
 * Central dispatch orchestrator for the Notifications module.
 *
 * Called by application-layer code when a notifiable event occurs.
 * NetMon uses this when an alert opens or resolves, but the service
 * itself has no knowledge of alerts, devices, or any NetMon-specific class.
 *
 * Async delivery:
 *   dispatch() creates notification + delivery records and enqueues them.
 *   Actual channel delivery is handled by the worker (scripts/notify.php),
 *   which is invoked separately — typically via cron.  This decouples SMTP
 *   latency and transient failures from the monitoring runner's hot path.
 *
 * Throttling:
 *   The caller decides when to call dispatch().  Throttle state lives
 *   in the caller's domain (e.g. alerts.last_notified_at for NetMon).
 *   This service does not implement throttling itself.
 *
 * Preference enforcement:
 *   When a NotificationPreferenceRepository is provided, dispatch() checks
 *   each user's per-channel preference before creating any delivery or queue
 *   record.  Default when no preference row exists: enabled (opt-out model).
 *   When the repository is not provided, all channels are enabled for all
 *   users (backwards-compatible behaviour — same as every row being missing).
 */
class NotificationService
{
    private NotificationRepository $repo;
    private NotificationQueueRepository $queueRepo;
    private ?NotificationPreferenceRepository $prefRepo;

    public function __construct(
        NotificationRepository             $repo,
        NotificationQueueRepository        $queueRepo,
        ?NotificationPreferenceRepository  $prefRepo = null
    ) {
        $this->repo      = $repo;
        $this->queueRepo = $queueRepo;
        $this->prefRepo  = $prefRepo;
    }

    // -------------------------------------------------------------------------
    // Dispatch
    // -------------------------------------------------------------------------

    /**
     * Generate a notification event and enqueue delivery to the given recipients.
     *
     * Flow:
     *   1. Insert module_notifications row → $notificationId
     *   2. For each recipient × channel:
     *      a. Insert module_notification_deliveries row (status='pending')
     *      b. Insert module_notification_queue row (available_at = now)
     *
     * The worker (scripts/notify.php) processes the queue asynchronously,
     * calling the appropriate channel for each queued item.
     *
     * Unknown or empty channel names are silently skipped, so passing 'email'
     * when the email channel is not yet configured is safe — the queue item
     * will be skipped by the worker when it processes it.
     *
     * @param array{
     *   source_type: string|null,
     *   source_id:   int|null,
     *   title:       string,
     *   body:        string,
     *   data:        array<mixed>
     * }                $event       Notification content and source context
     * @param array[]   $recipients  User rows (each must have 'id', 'email', etc.)
     * @param string[]  $channels    Channel names to deliver to: ['in_app', 'email']
     */
    public function dispatch(array $event, array $recipients, array $channels): void
    {
        if (empty($recipients) || empty($channels)) {
            return;
        }

        $notificationId = $this->repo->createNotification([
            'source_type' => $event['source_type'] ?? null,
            'source_id'   => $event['source_id']   ?? null,
            'title'       => $event['title'],
            'body'        => $event['body'],
            'data'        => isset($event['data']) ? json_encode($event['data']) : null,
        ]);

        $now = date('Y-m-d H:i:s');

        foreach ($recipients as $user) {
            $userId = (int) $user['id'];

            foreach ($channels as $channelName) {
                if (empty($channelName)) {
                    continue;
                }

                // Honour per-user channel preference.
                // Default (no row exists): enabled (opt-out model).
                // If no preference repository was injected, all channels are
                // treated as enabled — same as the missing-row default.
                if ($this->prefRepo !== null
                    && !$this->prefRepo->isChannelEnabled($userId, $channelName)
                ) {
                    continue;
                }

                $deliveryId = $this->repo->createDelivery([
                    'notification_id' => $notificationId,
                    'user_id'         => $userId,
                    'channel'         => $channelName,
                ]);

                $this->queueRepo->enqueue($deliveryId, $channelName, $now);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Inbox helpers — thin wrappers that keep callers from instantiating the repo
    // -------------------------------------------------------------------------

    /**
     * Return all in_app deliveries for a user (read + unread), newest first.
     */
    public function inboxForUser(int $userId, int $limit = 50): array
    {
        return $this->repo->findByUser($userId, $limit);
    }

    /**
     * Return unread in_app deliveries for a user, newest first.
     */
    public function unreadForUser(int $userId, int $limit = 50): array
    {
        return $this->repo->findUnreadByUser($userId, $limit);
    }

    /**
     * Return the unread count for a user (in_app channel).
     */
    public function countUnread(int $userId): int
    {
        return $this->repo->countUnreadByUser($userId);
    }

    /**
     * Mark a single delivery as read.
     *
     * The caller should verify ownership before calling this
     * (delivery.user_id == authenticated user) — see NotificationController.
     */
    public function markRead(int $deliveryId): void
    {
        $this->repo->markRead($deliveryId);
    }

    /**
     * Mark all unread in_app deliveries for a user as read.
     */
    public function markAllRead(int $userId): void
    {
        $this->repo->markAllReadByUser($userId);
    }
}
