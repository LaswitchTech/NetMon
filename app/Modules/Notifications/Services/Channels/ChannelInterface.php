<?php

namespace App\Modules\Notifications\Services\Channels;

/**
 * Contract for a single notification delivery channel.
 *
 * Implementations must never throw.  Errors are captured and returned so the
 * dispatcher can record outcomes and continue delivering to other channels.
 *
 * Each channel is stateless — all context it needs comes through the arguments.
 * The channel has no knowledge of domain entities (alerts, devices) — it only
 * knows about the notification content and the recipient user row.
 */
interface ChannelInterface
{
    /**
     * Deliver a notification to a single user.
     *
     * @param  array $notification  Row from module_notifications
     *                              (id, source_type, source_id, title, body, data, created_at)
     * @param  array $user          Row from users
     *                              (id, username, email, display_name, …)
     * @param  array $delivery      Row from module_notification_deliveries
     *                              (id, notification_id, user_id, channel, status, …)
     * @return array{status: 'sent'|'failed'|'skipped', error: string|null}
     */
    public function deliver(array $notification, array $user, array $delivery): array;

    /**
     * Return the canonical channel name stored in the deliveries table.
     * Examples: 'in_app', 'email', 'sms'
     */
    public function name(): string;
}
