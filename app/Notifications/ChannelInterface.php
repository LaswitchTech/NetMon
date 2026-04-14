<?php

namespace App\Notifications;

/**
 * Contract for notification channels.
 *
 * A channel is responsible for dispatching one notification attempt and
 * returning the outcome. It does NOT write to notification_history — that
 * is the caller's responsibility after the send attempt returns.
 */
interface ChannelInterface
{
    /**
     * Dispatch a notification.
     *
     * @param  string $type    Notification type: 'open', 'reminder', 'resolved'
     * @param  array  $alert   The alert row from the alerts table
     * @param  array  $device  Device context: id, name, target_address
     * @return array{
     *   status:  'sent'|'failed',
     *   message: string|null
     * }
     */
    public function send(string $type, array $alert, array $device): array;

    /**
     * Short machine-readable name for this channel (e.g. 'log', 'webhook').
     * Stored in notification_history.channel.
     */
    public function name(): string;

    /**
     * The delivery destination for this channel (e.g. log path, webhook URL).
     * Stored in notification_history.recipient for traceability.
     */
    public function recipient(): string;
}
