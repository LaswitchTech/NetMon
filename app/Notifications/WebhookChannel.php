<?php

namespace App\Notifications;

/**
 * Webhook notification channel.
 *
 * Sends an HTTP POST request with a JSON payload to a configured URL.
 * Compatible with any webhook receiver: Slack, Mattermost, custom endpoints, etc.
 *
 * Uses PHP's stream_context_create + file_get_contents — no curl dependency.
 *
 * Configure via config/notifications.php → channels.webhook:
 *   enabled: true
 *   url:     https://hooks.slack.com/services/...
 *   timeout: 5  (seconds)
 *
 * Payload shape:
 * {
 *   "event":            "open" | "reminder",
 *   "alert_id":         3,
 *   "alert_type":       "device_offline",
 *   "device_id":        1,
 *   "device_name":      "Core Router",
 *   "target_address":   "192.168.1.1",
 *   "occurrence_count": 8,
 *   "first_seen_at":    "2026-04-14 10:23:45",
 *   "last_seen_at":     "2026-04-14 10:38:00"
 * }
 */
class WebhookChannel implements ChannelInterface
{
    private string $url;
    private int    $timeoutSeconds;

    public function __construct(string $url, int $timeoutSeconds = 5)
    {
        $this->url            = $url;
        $this->timeoutSeconds = $timeoutSeconds;
    }

    public function name(): string
    {
        return 'webhook';
    }

    public function recipient(): string
    {
        return $this->url;
    }

    /**
     * POST JSON payload to the configured webhook URL.
     *
     * Returns status='failed' (rather than throwing) if the URL is empty,
     * the connection fails, or an HTTP error is returned — so the runner
     * can continue checking other devices.
     */
    public function send(string $type, array $alert, array $device): array
    {
        if ($this->url === '') {
            return [
                'status'  => 'failed',
                'message' => 'Webhook URL is not configured',
            ];
        }

        $payload = json_encode([
            'event'            => $type,
            'alert_id'         => $alert['id']              ?? null,
            'alert_type'       => $alert['alert_type']      ?? 'unknown',
            'device_id'        => $device['id']             ?? null,
            'device_name'      => $device['name']           ?? 'unknown',
            'target_address'   => $device['target_address'] ?? null,
            'occurrence_count' => $alert['occurrence_count'] ?? 1,
            'first_seen_at'    => $alert['first_seen_at']   ?? null,
            'last_seen_at'     => $alert['last_seen_at']    ?? null,
        ]);

        $context = stream_context_create([
            'http' => [
                'method'        => 'POST',
                'header'        => "Content-Type: application/json\r\nContent-Length: " . strlen($payload),
                'content'       => $payload,
                'timeout'       => $this->timeoutSeconds,
                'ignore_errors' => true,
            ],
        ]);

        // $http_response_header is populated by file_get_contents in this scope.
        $response = @file_get_contents($this->url, false, $context);

        if ($response === false) {
            return [
                'status'  => 'failed',
                'message' => 'Request to webhook URL failed',
            ];
        }

        // Extract HTTP status from the response headers populated by file_get_contents.
        // The variable is set in this scope after the call above.
        $statusCode = null;
        if (!empty($http_response_header)) {
            // First header line: "HTTP/1.1 200 OK"
            if (preg_match('/HTTP\/\S+\s+(\d{3})/', $http_response_header[0], $m)) {
                $statusCode = (int) $m[1];
            }
        }

        if ($statusCode !== null && $statusCode >= 400) {
            return [
                'status'  => 'failed',
                'message' => "HTTP {$statusCode} from webhook",
            ];
        }

        return [
            'status'  => 'sent',
            'message' => $statusCode !== null ? "HTTP {$statusCode}" : null,
        ];
    }
}
