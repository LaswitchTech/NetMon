# Notifications Module (Reusable)

> **Status:** Planned — not yet implemented.
> This is a design document. No schema, service, or view exists yet.
>
> Related: [architecture.md](architecture.md) · [notifications.md](notifications.md) · [domain-model.md](domain-model.md)

---

## Scope Distinction

There are **two separate notification systems** in this codebase. Understanding the boundary between them is critical:

| System | Location | Purpose | Status |
|--------|----------|---------|--------|
| **Alert dispatch** | `App\Notifications\` · `notification_history` table | Dispatches monitoring alerts via log/webhook channels. Tightly coupled to `alerts` and `devices`. | Implemented (Phase 7) |
| **Notifications module** (this doc) | `app/Modules/Notifications/` | General-purpose notification delivery: in-app inbox, email, future SMS. Not tied to alerts or devices. | Planned |

The existing alert dispatch system should remain as-is. This module is a separate, higher-level system that NetMon (and future apps) can use to deliver structured messages to users through multiple channels.

---

## Purpose

The Notifications module provides a **unified, multi-channel notification delivery system** that:

- Maintains a persistent per-user notification inbox (in-app channel)
- Delivers notifications via email (SMTP)
- Is designed to support SMS in a future phase
- Is decoupled from any specific domain model (devices, alerts, etc.)
- Can be used by any app built on this platform

---

## Design Principles

- **Generated from domain events, not raw data.** NetMon uses this module by calling `NotificationService::dispatch()` when an alert opens or resolves — not from inside the monitoring runner's raw check loop.
- **Delivery decoupled from generation.** Creating a notification record and delivering it are separate concerns. A notification can exist in the inbox before email delivery has completed.
- **Per-user, per-channel delivery records.** One notification event can produce multiple delivery records (one per user per channel).
- **Throttling at dispatch time.** The caller (NetMon's alert system) decides when to call `dispatch()`. Throttle state lives in the caller's domain (e.g. `alerts.last_notified_at`), not in this module.
- **No NetMon-specific dependencies.** This module must not import `DeviceRepository`, `AlertRepository`, or any `App\NetMon\` class.

---

## Module Location

```
app/Modules/Notifications/
    Models/
        NotificationRepository.php       ← Read/write for notifications + deliveries
    Services/
        NotificationService.php          ← Dispatch orchestration
        Channels/
            ChannelInterface.php         ← Contract: deliver one notification to one user
            InAppChannel.php             ← Persist a delivery record (inbox)
            EmailChannel.php             ← SMTP delivery
            SmsChannel.php               ← (future — placeholder only)
```

---

## Schema

### `module_notifications`

One row per notification event. Stores the content and source context.

Migration number: **0022**.

> The table is prefixed `module_` to avoid collision with any existing app-level `notifications` table.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | INTEGER PK | No | — | Auto-increment |
| `source_type` | VARCHAR(64) | Yes | NULL | What generated this notification: `alert`, `device`, `system`, or NULL for system-wide messages |
| `source_id` | INTEGER | Yes | NULL | The ID of the source entity. Not a FK. NULL for broadcasts or system messages. |
| `title` | VARCHAR(255) | No | — | Short human-readable subject line |
| `body` | TEXT | No | — | Full notification body. Plain text. |
| `data` | TEXT | Yes | NULL | JSON-encoded arbitrary payload for channel-specific rendering (e.g. alert severity, device link) |
| `created_at` | VARCHAR(32) | No | — | When the notification was generated |

**Indexes:**
- `module_notifications_source (source_type, source_id)` — look up all notifications for a source entity

---

### `module_notification_deliveries`

One row per user per channel per notification. Tracks delivery state and read state.

Migration number: **0023**.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | INTEGER PK | No | — | Auto-increment |
| `notification_id` | INTEGER FK | No | — | → `module_notifications.id` CASCADE DELETE |
| `user_id` | INTEGER FK | No | — | → `users.id` CASCADE DELETE |
| `channel` | VARCHAR(32) | No | — | `in_app`, `email`, `sms` |
| `status` | VARCHAR(16) | No | `pending` | `pending`, `sent`, `failed`, `skipped` |
| `read_at` | VARCHAR(32) | Yes | NULL | For `in_app` channel: when the user read/dismissed it. NULL = unread. |
| `sent_at` | VARCHAR(32) | Yes | NULL | When the delivery was attempted |
| `error` | VARCHAR(255) | Yes | NULL | Error detail if `status = 'failed'` |
| `created_at` | VARCHAR(32) | No | — | When this delivery record was created |

**Indexes:**
- `module_notification_deliveries_user_unread (user_id, channel, read_at)` — unread inbox count (hot path)
- `module_notification_deliveries_notification_id` — all deliveries for one notification

---

## Channel Interface

```php
namespace App\Modules\Notifications\Services\Channels;

interface ChannelInterface
{
    /**
     * Deliver a notification to a single user.
     *
     * @param  array $notification  Row from module_notifications
     * @param  array $user          Row from users (id, email, display_name, etc.)
     * @param  array $delivery      Row from module_notification_deliveries
     * @return array{status: 'sent'|'failed'|'skipped', error: string|null}
     */
    public function deliver(array $notification, array $user, array $delivery): array;

    public function name(): string;   // 'in_app', 'email', 'sms'
}
```

Channels never throw. They return `failed` with an `error` string so the dispatcher can record the outcome and continue to other channels.

---

## InAppChannel

The in-app channel does not send anything externally. It marks the delivery record as `sent` immediately — the record itself IS the notification in the user's inbox.

```
deliver() → update delivery.status = 'sent', delivery.sent_at = now
```

The notification appears in the user's inbox as an unread item until `read_at` is set (via the inbox UI).

---

## EmailChannel

Sends a plaintext (or simple HTML) email via SMTP using PHP's native `mail()` or a thin wrapper. No third-party mailer library.

**Configuration** (via `config/notifications-module.php` or `config/local.php` override):

```php
return [
    'email' => [
        'enabled'     => false,          // env: NOTIFY_EMAIL_ENABLED
        'from_address'=> '',             // env: NOTIFY_EMAIL_FROM
        'from_name'   => 'NetMon',       // env: NOTIFY_EMAIL_FROM_NAME
        'smtp_host'   => '',             // env: NOTIFY_SMTP_HOST
        'smtp_port'   => 587,            // env: NOTIFY_SMTP_PORT
        'smtp_user'   => '',             // env: NOTIFY_SMTP_USER
        'smtp_pass'   => '',             // env: NOTIFY_SMTP_PASS
        'smtp_tls'    => true,           // env: NOTIFY_SMTP_TLS
    ],
];
```

The email body uses the `title` as subject and `body` as the plain-text message. A minimal HTML wrapper can be added later.

---

## NotificationService

**Class:** `App\Modules\Notifications\Services\NotificationService`

Central dispatch orchestrator. Called by application-layer code (e.g. `scripts/monitor.php` extended, or a future `AlertObserver`).

```php
/**
 * Generate a notification and deliver it to the given users via all active channels.
 *
 * @param array{
 *   source_type: string|null,
 *   source_id:   int|null,
 *   title:       string,
 *   body:        string,
 *   data:        array
 * } $event
 * @param array[] $recipients  Each entry: user row from UserRepository
 * @param string[] $channels   e.g. ['in_app', 'email']
 */
public function dispatch(array $event, array $recipients, array $channels): void;

/**
 * Return unread in_app deliveries for a user (for the inbox UI).
 */
public function unreadForUser(int $userId, int $limit = 50): array;

/**
 * Mark a delivery as read.
 */
public function markRead(int $deliveryId): void;
```

### Dispatch flow

```
dispatch(event, recipients, channels)
    │
    ├─ INSERT module_notifications row → $notificationId
    │
    └─ for each $recipient:
           for each $channel:
               INSERT module_notification_deliveries (status='pending') → $deliveryId
               $result = Channel::deliver(notification, user, delivery)
               UPDATE module_notification_deliveries SET status=$result.status, ...
```

Delivery is synchronous in Phase 1. Async delivery (via a queue or background job) is a future improvement.

---

## NotificationRepository

**Class:** `App\Modules\Notifications\Models\NotificationRepository`

| Method | Description |
|--------|-------------|
| `createNotification(array $data): int` | Insert module_notifications row; return ID |
| `createDelivery(array $data): int` | Insert module_notification_deliveries row; return ID |
| `updateDelivery(int $id, array $fields): void` | Update status/sent_at/error on a delivery row |
| `findUnreadByUser(int $userId, int $limit): array` | Unread in_app deliveries with notification content JOINed |
| `countUnreadByUser(int $userId): int` | Unread badge count for the UI |
| `markRead(int $deliveryId): void` | Set read_at = now on an in_app delivery |
| `findBySource(string $type, int $id): array` | All notifications for a source entity |

---

## NetMon Integration Plan

### How NetMon uses this module

NetMon's monitoring runner currently handles its own notification dispatch inline in `scripts/monitor.php`. The long-term design is:

1. **Keep existing `App\Notifications\`** for the log and webhook channels — these are infrastructure-level sinks, not user-facing notifications.
2. **Add `NotificationService::dispatch()`** calls alongside the existing dispatch logic for user-facing channels (in-app inbox + email).

This means the alert notification block in `monitor.php` would gain an additional call:

```php
// Existing: log/webhook dispatch (unchanged)
foreach ($channels as $channel) {
    $channel->send($type, $alert, $device);
}

// New: user-facing notification (added)
$notificationService->dispatch(
    [
        'source_type' => 'alert',
        'source_id'   => $alert['id'],
        'title'       => "Device offline: {$device['name']}",
        'body'        => "...",
        'data'        => ['alert_id' => $alert['id'], 'device_id' => $device['id']],
    ],
    $adminRecipients,   // fetched from UserRepository once per monitor run
    ['in_app', 'email']
);
```

The throttle is still controlled by `alerts.last_notified_at` — not by this module.

### User-facing notification inbox

Once the module exists, a simple inbox UI can be added:

```
GET /notifications        — user's unread + recent notifications
POST /notifications/{id}/read  — mark one as read
```

An unread count badge in the navigation bar header would call `countUnreadByUser($userId)` and be updated via AJAX or on page load.

---

## Notification Preferences (Future)

Phase 1 delivers to all provided recipients on all specified channels. A future phase adds per-user, per-channel opt-out:

```sql
-- Future table (not part of Phase 1)
module_notification_preferences
  user_id     INTEGER FK
  channel     VARCHAR(32)
  event_type  VARCHAR(64)   -- 'alert.open', 'alert.resolved', etc.
  enabled     INTEGER       -- 1 = receive, 0 = opt-out
```

The `NotificationService::dispatch()` signature should be written so that adding preference checks is an internal change — callers do not need to change.

---

## What Is NOT in Scope for Phase 1

- Async/queued delivery
- Notification preferences (opt-in/opt-out per user)
- SMS channel (schema is ready; implementation deferred)
- Digest / batching (group multiple events into one email)
- Rich HTML email templates
- Notification read state for email (open tracking)
- Admin view of all notification deliveries

---

## Implementation Checklist

When implementing this module:

- [ ] Migration `0022_create_module_notifications_table.php`
- [ ] Migration `0023_create_module_notification_deliveries_table.php`
- [ ] `app/Modules/Notifications/Services/Channels/ChannelInterface.php`
- [ ] `app/Modules/Notifications/Services/Channels/InAppChannel.php`
- [ ] `app/Modules/Notifications/Services/Channels/EmailChannel.php`
- [ ] `app/Modules/Notifications/Services/NotificationService.php`
- [ ] `app/Modules/Notifications/Models/NotificationRepository.php`
- [ ] Register `NotificationService` in `public/index.php` container
- [ ] Route: `GET /notifications`, `POST /notifications/{id}/read`
- [ ] Update `docs/notifications.md` to reference this module
