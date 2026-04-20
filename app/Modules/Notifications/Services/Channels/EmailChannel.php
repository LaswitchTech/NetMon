<?php

namespace App\Modules\Notifications\Services\Channels;

use App\Modules\Notifications\Models\NotificationRepository;

/**
 * Email notification channel.
 *
 * Sends a plain-text email per delivery row via SMTP.
 * Configuration is injected at construction time from config/notifications-module.php.
 *
 * Delivery behaviour:
 *   - 'sent'    → email was accepted by the SMTP server
 *   - 'failed'  → SMTP connection or protocol error (error stored in delivery.error)
 *   - 'skipped' → channel disabled, no recipient address, or no SMTP host configured
 *
 * This channel never throws. All exceptions are caught and converted to a
 * 'failed' outcome so the NotificationService dispatch loop can continue
 * delivering to other channels.
 *
 * No NetMon-specific dependencies — this class only knows about the
 * notification content and the recipient user row.
 */
class EmailChannel implements ChannelInterface
{
    private NotificationRepository $repo;

    /** @var array Email sub-array from config/notifications-module.php */
    private array $config;

    /**
     * @param NotificationRepository $repo
     * @param array $config  The 'email' sub-array from notifications-module config.
     *                       Expected keys: enabled, from_address, from_name,
     *                       smtp_host, smtp_port, smtp_user, smtp_pass, encryption.
     */
    public function __construct(NotificationRepository $repo, array $config)
    {
        $this->repo   = $repo;
        $this->config = $config;
    }

    public function deliver(array $notification, array $user, array $delivery): array
    {
        $now = date('Y-m-d H:i:s');

        // ── Guard: channel not enabled ────────────────────────────────────────
        if (empty($this->config['enabled'])) {
            return $this->skip($delivery['id'], $now, 'Email channel is not enabled.');
        }

        // ── Guard: no SMTP host configured ────────────────────────────────────
        if (empty($this->config['smtp_host'])) {
            return $this->skip($delivery['id'], $now, 'SMTP host is not configured.');
        }

        // ── Guard: no from address configured ────────────────────────────────
        if (empty($this->config['from_address'])) {
            return $this->skip($delivery['id'], $now, 'Email from_address is not configured.');
        }

        // ── Guard: recipient has no email address ─────────────────────────────
        $to = trim($user['email'] ?? '');
        if ($to === '') {
            return $this->skip($delivery['id'], $now, 'Recipient has no email address.');
        }

        // ── Send ──────────────────────────────────────────────────────────────
        try {
            $mailer = new SmtpMailer();
            $mailer->send(
                $this->config,
                $to,
                $notification['title'],
                $notification['body']
            );

            $this->repo->updateDelivery($delivery['id'], [
                'status'  => 'sent',
                'sent_at' => $now,
                'error'   => null,
            ]);

            return ['status' => 'sent', 'error' => null];

        } catch (\Throwable $e) {
            // Truncate to fit the VARCHAR(255) error column.
            $error = substr($e->getMessage(), 0, 255);

            $this->repo->updateDelivery($delivery['id'], [
                'status'  => 'failed',
                'sent_at' => $now,
                'error'   => $error,
            ]);

            return ['status' => 'failed', 'error' => $error];
        }
    }

    public function name(): string
    {
        return 'email';
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function skip(int $deliveryId, string $now, string $reason): array
    {
        $this->repo->updateDelivery($deliveryId, [
            'status'  => 'skipped',
            'sent_at' => $now,
            'error'   => $reason,
        ]);

        return ['status' => 'skipped', 'error' => $reason];
    }
}
