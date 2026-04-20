<?php

namespace App\Modules\Notifications\Services\Channels;

use App\Modules\Notifications\Models\NotificationRepository;

/**
 * In-app notification channel.
 *
 * Does not send anything externally.  The delivery record itself IS the
 * notification in the user's inbox.  This channel simply marks the delivery
 * row as 'sent' — the in-app inbox UI reads the sent, unread delivery rows
 * to build the user's notification list.
 *
 * Read state (read_at) is managed separately via NotificationRepository::markRead()
 * when the user views or dismisses a notification.
 */
class InAppChannel implements ChannelInterface
{
    private NotificationRepository $repo;

    public function __construct(NotificationRepository $repo)
    {
        $this->repo = $repo;
    }

    public function deliver(array $notification, array $user, array $delivery): array
    {
        $now = date('Y-m-d H:i:s');

        $this->repo->updateDelivery($delivery['id'], [
            'status'  => 'sent',
            'sent_at' => $now,
        ]);

        return ['status' => 'sent', 'error' => null];
    }

    public function name(): string
    {
        return 'in_app';
    }
}
