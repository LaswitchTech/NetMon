<?php

namespace App\Notifications;

/**
 * Log-file notification channel.
 *
 * Appends a structured plain-text line to a log file on every notification.
 * Requires no external services or credentials — works out of the box in
 * development and as an audit trail in production.
 *
 * Default log path: storage/logs/notifications.log
 * Configurable via config/notifications.php → channels.log.path
 *
 * Log line format:
 *   [2026-04-14 10:23:45] OPEN     device_offline — Core Router (192.168.1.1) — alert #3, occurrence #1
 *   [2026-04-14 10:38:45] REMINDER device_offline — Core Router (192.168.1.1) — alert #3, occurrence #8
 */
class LogChannel implements ChannelInterface
{
    private string $logPath;

    public function __construct(string $logPath)
    {
        $this->logPath = $logPath;
    }

    public function name(): string
    {
        return 'log';
    }

    public function recipient(): string
    {
        return $this->logPath;
    }

    /**
     * Append one notification line to the log file.
     *
     * Fails gracefully if the log directory does not exist or is not writable —
     * returns status='failed' rather than throwing, so the runner can continue.
     */
    public function send(string $type, array $alert, array $device): array
    {
        $dir = dirname($this->logPath);

        if (!is_dir($dir)) {
            return [
                'status'  => 'failed',
                'message' => "Log directory does not exist: {$dir}",
            ];
        }

        $label       = strtoupper(str_pad($type, 8));
        $alertId     = $alert['id'] ?? '?';
        $occurrences = $alert['occurrence_count'] ?? 1;
        $alertType   = $alert['alert_type'] ?? 'unknown';
        $deviceName  = $device['name'] ?? 'unknown';
        $address     = $device['target_address'] ?? '?';
        $timestamp   = date('Y-m-d H:i:s');

        $line = sprintf(
            "[%s] %s %s — %s (%s) — alert #%s, occurrence #%s\n",
            $timestamp,
            $label,
            $alertType,
            $deviceName,
            $address,
            $alertId,
            $occurrences
        );

        $written = file_put_contents($this->logPath, $line, FILE_APPEND | LOCK_EX);

        if ($written === false) {
            return [
                'status'  => 'failed',
                'message' => "Could not write to log file: {$this->logPath}",
            ];
        }

        return [
            'status'  => 'sent',
            'message' => null,
        ];
    }
}
