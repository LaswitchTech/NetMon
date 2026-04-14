# Notifications

> **Status (Phase 7):** Log and webhook channels implemented. Notifications fire on `device_offline` alert open and reminder events, with 15-minute throttling. Resolved notifications, email channel, and per-device configuration are planned but not yet built.
>
> Related: [alerts.md](alerts.md) · [monitoring.md](monitoring.md) · [domain-model.md](domain-model.md) · [schema.md](schema.md)

---

## Overview

Notifications in NetMon are driven by alerts — not by raw monitoring events. The monitoring runner evaluates alert state on every pass and dispatches a notification only when:

1. An alert transitions to `open` (first failure detected), OR
2. A `reminder` is due for an already-open alert (throttle window elapsed).

This means a device that stays offline for an hour produces one `open` notification and periodic `reminder` notifications — not one per monitoring cycle.

---

## Throttle Logic

The throttle is implemented on the alert row via the `last_notified_at` timestamp. On every monitoring pass, the runner evaluates:

```php
if ($alert['last_notified_at'] === null) {
    // First notification — fire immediately.
    return true;
}
return (time() - strtotime($alert['last_notified_at'])) >= $throttleSeconds;
```

`$throttleSeconds` defaults to 900 (15 minutes) and is configurable via `NOTIFY_THROTTLE_SECONDS` in `.env` or `config/local.php`.

After a notification is dispatched, the runner updates `alerts.last_notified_at = now`. The next reminder will not fire until `$throttleSeconds` have elapsed from that timestamp.

**Throttle state is persisted in the database.** If the monitoring runner restarts between cycles, the throttle is not reset — the correct elapsed time is computed from the stored timestamp.

---

## Notification Types

| Type | When fired | Notes |
|------|------------|-------|
| `open` | First time a `device_offline` alert is created | `last_notified_at` is NULL on a new alert, so this fires immediately |
| `reminder` | Every `$throttleSeconds` while the alert remains open | Same condition evaluated on re-confirmation |
| `resolved` | *(planned)* When an open alert is resolved | Not yet dispatched |

---

## Channels

Channels implement `App\Notifications\ChannelInterface`:

```php
interface ChannelInterface {
    public function send(string $type, array $alert, array $device): array;
    public function name(): string;
    public function recipient(): string;
}
```

`send()` returns `['status' => 'sent'|'failed', 'message' => string|null]`. Channels never throw — they return `'failed'` with a message so the runner can continue checking other devices.

### Log channel (`App\Notifications\LogChannel`)

Appends one line per notification to a local log file. No external dependencies.

**Default path:** `storage/logs/notifications.log`

**Line format:**
```
[2026-04-14 10:23:45] OPEN     device_offline — Core Router (192.168.1.1) — alert #3, occurrence #1
[2026-04-14 10:38:45] REMINDER device_offline — Core Router (192.168.1.1) — alert #3, occurrence #8
```

**Configuration:**

| Env var | Default | Description |
|---------|---------|-------------|
| `NOTIFY_LOG_ENABLED` | `true` | Enable/disable the log channel |
| `NOTIFY_LOG_PATH` | `storage/logs/notifications.log` | Destination file path |

The channel returns `status='failed'` if the log directory does not exist or is not writable. It does not attempt to create missing directories.

### Webhook channel (`App\Notifications\WebhookChannel`)

HTTP POST with a JSON payload to any webhook URL. Compatible with Slack incoming webhooks, Mattermost, custom endpoints, etc.

Uses `stream_context_create` + `file_get_contents` — no curl dependency.

**JSON payload shape:**
```json
{
  "event":            "open",
  "alert_id":         3,
  "alert_type":       "device_offline",
  "device_id":        1,
  "device_name":      "Core Router",
  "target_address":   "192.168.1.1",
  "occurrence_count": 1,
  "first_seen_at":    "2026-04-14 10:23:45",
  "last_seen_at":     "2026-04-14 10:23:45"
}
```

**Configuration:**

| Env var | Default | Description |
|---------|---------|-------------|
| `NOTIFY_WEBHOOK_ENABLED` | `false` | Enable/disable the webhook channel |
| `NOTIFY_WEBHOOK_URL` | *(empty)* | Full webhook URL |
| `NOTIFY_WEBHOOK_TIMEOUT` | `5` | HTTP request timeout in seconds |

The channel returns `status='failed'` if:
- The URL is empty
- The HTTP request cannot be completed (network error)
- The server returns HTTP 400 or above

---

## Configuration

Channel settings are defined in `config/notifications.php` and can be overridden in `config/local.php` or via environment variables.

**`config/notifications.php` keys:**

```php
return [
    'throttle_seconds' => 900,      // env: NOTIFY_THROTTLE_SECONDS
    'channels' => [
        'log' => [
            'enabled' => true,      // env: NOTIFY_LOG_ENABLED
            'path'    => 'storage/logs/notifications.log',  // env: NOTIFY_LOG_PATH
        ],
        'webhook' => [
            'enabled' => false,     // env: NOTIFY_WEBHOOK_ENABLED
            'url'     => '',        // env: NOTIFY_WEBHOOK_URL
            'timeout' => 5,         // env: NOTIFY_WEBHOOK_TIMEOUT
        ],
    ],
];
```

To enable webhook notifications in `config/local.php`:

```php
return [
    'notifications' => [
        'channels' => [
            'webhook' => [
                'enabled' => true,
                'url'     => 'https://hooks.slack.com/services/...',
            ],
        ],
    ],
];
```

---

## Schema: `notification_history`

Immutable append-only log of every notification dispatch attempt.

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| `id` | INTEGER PK | No | — | Auto-increment |
| `alert_id` | INTEGER FK | No | — | → `alerts.id` CASCADE DELETE |
| `channel` | VARCHAR(32) | No | — | Channel name: `log`, `webhook`, etc. |
| `recipient` | VARCHAR(255) | No | — | Log file path or webhook URL |
| `notification_type` | VARCHAR(32) | No | — | `open`, `reminder`, `resolved` |
| `status` | VARCHAR(16) | No | — | `sent` or `failed` |
| `message` | VARCHAR(255) | Yes | NULL | Error detail on failure; HTTP status on success |
| `sent_at` | VARCHAR(32) | No | — | When the dispatch was attempted |
| `created_at` | VARCHAR(32) | No | — | Row insertion time |

**Indexes:**
- `notification_history_alert_id` — all notifications for a given alert
- `notification_history_sent_at` — time-range queries

**Migration 0016.**

---

## Integration with the Monitoring Runner

The notification block in `scripts/monitor.php` runs after alert state is updated, inside the per-device loop:

```
For each device:
    1. Pinger::check(target_address)
    2. DeviceCheckRepository::saveCheck()
    3. DeviceCheckRepository::updateDeviceStatus()
    4. Alert logic → sets $pendingNotification (type + alert row)
    5. Notification block:
         if $pendingNotification set AND channels configured AND throttle elapsed:
             foreach channel: send() → record in notification_history
             alerts.last_notified_at = now
```

`$pendingNotification` is a local variable that bridges the alert block and the notification block without nested conditionals. It carries the notification type (`open` or `reminder`) and the alert array needed by channels.

**Dry-run mode** (`--dry-run`) skips all notification sends and `notification_history` writes.

**Verbose mode** (`--verbose`) prints notification events as indented sub-lines:
```
  [OFFLINE]  Core Router              192.168.1.1          —
             ↳ alert #3 opened: device_offline
             ↳ notify [log] ✓ open
             ↳ notify [webhook] ✓ open (HTTP 200)
```

---

## NotificationRepository

**Class:** `App\Models\NotificationRepository`

| Method | Description |
|--------|-------------|
| `record(array $data): int` | Insert one `notification_history` row; return new ID |
| `findRecentByAlert(int $alertId, int $limit = 10): array` | Fetch recent notifications for a given alert |

---

## What Remains

| Item | Notes |
|------|-------|
| `resolved` notification type | Currently alerts resolve silently; the runner does not dispatch a notification on recovery |
| Email channel | Requires SMTP configuration; planned but not yet built |
| Per-device throttle override | Currently global; could become a column on `monitored_services` or `devices` |
| Alert `acknowledged` / `suppressed` states | Browser UI to suppress notifications for a specific alert |
| Service-level notifications | Requires `monitored_services` + `service_checks` (future phase) |
| Notification UI | Admin view of `notification_history` with filtering by alert, channel, status |
