# Notifications Module (Reusable)

> **Status:** Core module structure, schema, in-app inbox, email channel, async queue, and
> NetMon monitor integration are all implemented. Dispatch is now asynchronous — monitor.php
> enqueues items; the worker (scripts/notify.php) delivers them via in_app and email channels.
> SMS is deferred.
>
> Related: [architecture.md](architecture.md) · [notifications.md](notifications.md) · [domain-model.md](domain-model.md)

---

## Scope Distinction

There are **two separate notification systems** in this codebase. Understanding the boundary between them is critical:

| System | Location | Purpose | Status |
|--------|----------|---------|--------|
| **Alert dispatch** | `App\Notifications\` · `notification_history` table | Dispatches monitoring alerts via log/webhook channels. Tightly coupled to `alerts` and `devices`. | Implemented |
| **Notifications module** (this doc) | `app/Modules/Notifications/` | General-purpose notification delivery: in-app inbox, email, future SMS. Not tied to alerts or devices. | Implemented |

The existing alert dispatch system remains as-is. This module is a separate, higher-level system that NetMon (and future apps) use to deliver structured messages to users through multiple channels.

---

## Purpose

The Notifications module provides a **unified, multi-channel notification delivery system** that:

- Maintains a persistent per-user notification inbox (in-app channel)
- Delivers notifications via email (SMTP — active when configured)
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
    Controllers/
        NotificationController.php       ← Inbox page, mark-read, mark-all-read, unread count
    Models/
        NotificationRepository.php       ← Read/write for notifications + deliveries
        NotificationQueueRepository.php  ← Async delivery queue: enqueue, fetchDue, reschedule, delete
    Services/
        NotificationService.php          ← Dispatch orchestration (enqueues items) + inbox helpers
        Channels/
            ChannelInterface.php         ← Contract: deliver one notification to one user
            InAppChannel.php             ← Mark delivery sent (the record IS the inbox item)
            EmailChannel.php             ← SMTP delivery; skips gracefully if not configured
            SmtpMailer.php               ← Raw SMTP client (no external libraries)

scripts/
    notify.php                           ← Queue worker: fetches due items, delivers, retries
```

---

## Schema

### `module_notification_queue`

One row per pending delivery item.  Created by `NotificationService::dispatch()` (one row per user per channel per notification event) and consumed by the worker (`scripts/notify.php`).

Migration number: **0024**.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | INTEGER PK | No | — | Auto-increment |
| `delivery_id` | INTEGER FK | No | — | → `module_notification_deliveries.id` CASCADE DELETE |
| `channel` | VARCHAR(32) | No | — | `in_app`, `email`, `sms` |
| `attempts` | INTEGER | No | `0` | Number of delivery attempts made so far |
| `max_attempts` | INTEGER | No | `3` | Maximum attempts before the item is abandoned |
| `available_at` | VARCHAR(32) | No | — | Earliest datetime the worker should process this item |
| `created_at` | VARCHAR(32) | No | — | When the queue row was inserted |

**Indexes:**
- `module_notification_queue_available_at` — primary worker fetch by due time
- `module_notification_queue_delivery_id` — look up queue rows for a specific delivery

---

### `module_notifications`

One row per notification event. Stores the content and source context.

Migration number: **0022**.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | INTEGER PK | No | — | Auto-increment |
| `source_type` | VARCHAR(64) | Yes | NULL | What generated this notification: `alert`, `device`, `system`, or NULL |
| `source_id` | INTEGER | Yes | NULL | The ID of the source entity. Not a FK. NULL for broadcasts or system messages. |
| `title` | VARCHAR(255) | No | — | Short human-readable subject line |
| `body` | TEXT | No | — | Full notification body. Plain text. |
| `data` | TEXT | Yes | NULL | JSON-encoded arbitrary payload |
| `created_at` | VARCHAR(32) | No | — | When the notification was generated |

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
| `read_at` | VARCHAR(32) | Yes | NULL | For `in_app` channel: when the user read/dismissed it |
| `sent_at` | VARCHAR(32) | Yes | NULL | When the delivery was attempted |
| `error` | VARCHAR(255) | Yes | NULL | Error detail if `status = 'failed'` or reason if `status = 'skipped'` |
| `created_at` | VARCHAR(32) | No | — | When this delivery record was created |

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

Channels **never throw**. They return `failed` with an `error` string so the dispatcher records the outcome and continues to other channels without interruption.

---

## InAppChannel

The in-app channel does not send anything externally. It marks the delivery record as `sent` immediately — the record itself IS the notification in the user's inbox.

```
deliver() → UPDATE delivery SET status='sent', sent_at=now
```

The notification appears in the user's inbox as an unread item until `read_at` is set via the inbox UI.

---

## EmailChannel

Sends a plain-text email via SMTP using `SmtpMailer` (no third-party libraries).
Configuration is injected at construction time from `config/notifications-module.php`.

### Delivery outcomes

| Outcome | Condition |
|---------|-----------|
| `sent` | SMTP server accepted the message (250 response to DATA body) |
| `failed` | Connection error, protocol error, or auth failure — error message stored in `delivery.error` (truncated to 255 chars) |
| `skipped` | Channel not enabled, no SMTP host configured, no from address configured, or recipient has no email address |

### Failure isolation

`EmailChannel::deliver()` wraps the entire send in a `catch (\Throwable $e)` block. Any SMTP failure — connection refused, TLS negotiation error, authentication failure, unexpected server response — is caught, stored in `delivery.error`, and returned as `['status' => 'failed', ...]`. The `NotificationService` dispatch loop continues to the next channel and recipient without interruption. **In-app delivery is never affected by email failure.**

### Configuration

Email is configured in `config/notifications-module.php` (env-driven, overridable via `config/local.php`):

```php
// config/local.php — recommended way to activate email in production
return [
    'notifications-module' => [
        'email' => [
            'enabled'      => true,
            'from_address' => 'netmon@example.com',
            'from_name'    => 'NetMon Alerts',
            'smtp_host'    => 'smtp.example.com',
            'smtp_port'    => 587,
            'smtp_user'    => 'netmon@example.com',
            'smtp_pass'    => 'secret',
            'encryption'   => 'tls',   // 'tls' | 'ssl' | 'none'
        ],
    ],
];
```

Environment variables (all optional — local.php values take precedence):

| Variable | Default | Purpose |
|----------|---------|---------|
| `NOTIFY_EMAIL_ENABLED` | `false` | Master switch |
| `NOTIFY_EMAIL_FROM` | `` | From address |
| `NOTIFY_EMAIL_FROM_NAME` | `NetMon` | From display name |
| `NOTIFY_SMTP_HOST` | `` | SMTP server hostname |
| `NOTIFY_SMTP_PORT` | `587` | SMTP port |
| `NOTIFY_SMTP_USER` | `` | SMTP username (blank = anonymous) |
| `NOTIFY_SMTP_PASS` | `` | SMTP password |
| `NOTIFY_SMTP_ENCRYPTION` | `tls` | Transport: `tls`, `ssl`, or `none` |

### Transport modes

| `encryption` | Mechanism | Typical port |
|---|---|---|
| `tls` | STARTTLS — plain TCP upgraded via STARTTLS command | 587 |
| `ssl` | SMTPS — TLS socket from first byte (`ssl://host`) | 465 |
| `none` | Plain SMTP, no encryption — local relay / dev only | 25 |

### SmtpMailer

`SmtpMailer` is a self-contained class in the same `Channels/` directory. It:

- Uses `stream_socket_client()` for all socket operations (no `fsockopen`, no exec)
- Verifies SSL certificates by default (`verify_peer = true`)
- Implements AUTH LOGIN (skipped when `smtp_user` is blank)
- Follows RFC 5321 dot-stuffing on the message body
- Encodes non-ASCII header values as RFC 2047 UTF-8 base64
- Has a 10-second per-operation timeout
- Throws `\RuntimeException` on any failure — caught by `EmailChannel`

---

## NotificationService

**Class:** `App\Modules\Notifications\Services\NotificationService`

Central dispatch orchestrator. Called by application-layer code when a notifiable event occurs.

```php
/**
 * Generate a notification and enqueue delivery to the given users via the given channels.
 *
 * @param array{
 *   source_type: string|null,
 *   source_id:   int|null,
 *   title:       string,
 *   body:        string,
 *   data:        array
 * } $event
 * @param array[] $recipients  Each entry: user row from UserRepository (must include 'id')
 * @param string[] $channels   e.g. ['in_app', 'email']
 */
public function dispatch(array $event, array $recipients, array $channels): void;
```

**Constructor:** `__construct(NotificationRepository $repo, NotificationQueueRepository $queueRepo, ?NotificationPreferenceRepository $prefRepo = null)`

The third argument is optional for backwards compatibility. In production (`scripts/monitor.php`) it is always provided.

Unknown or empty channel names are silently skipped.

### Dispatch flow (async)

```
dispatch(event, recipients, channels)
    │
    ├─ INSERT module_notifications row → $notificationId
    │
    └─ for each $recipient × $channel:
           check preference: isChannelEnabled(userId, channel)
               │
               ├─ disabled → skip (no delivery row, no queue row)
               │
               └─ enabled (or no row → default enabled):
                      INSERT module_notification_deliveries (status='pending') → $deliveryId
                      INSERT module_notification_queue (delivery_id, channel, available_at=now)
                      // Worker delivers asynchronously
```

**Default when no preference row exists:** channel is enabled. This is the opt-out model — users receive notifications on all channels unless they explicitly disable one.

---

## Queue Worker (`scripts/notify.php`)

Invoked from cron (typically every minute). Each run processes all items in `module_notification_queue` where `available_at <= now`.

### Cron schedule

```
* * * * * php /path/to/scripts/notify.php >> /path/to/storage/logs/notify.log 2>&1
```

### Worker flow

```
fetchDue(now)
    │
    └─ for each queue item:
           load delivery row (+ notification JOIN)
           load user row
           look up channel by name in registry
           if channel not registered → delete queue row, skip
           channel->deliver(notification, user, delivery)
               │
               ├─ sent / skipped → delete queue row
               │
               └─ failed:
                    new_attempts = attempts + 1
                    if new_attempts < max_attempts:
                        reschedule (available_at = now + new_attempts × 60s)
                    else:
                        delete queue row (delivery already marked failed)
```

### Retry back-off

| Failure | Delay before retry |
|---------|-------------------|
| 1st | 60 s |
| 2nd | 120 s |
| 3rd (exhausted, default max=3) | abandoned |

### Usage

```bash
php scripts/notify.php            # process all due queue items
php scripts/notify.php --verbose  # show per-item detail
php scripts/notify.php --dry-run  # fetch due items but do not deliver
```

---

## NotificationQueueRepository

**Class:** `App\Modules\Notifications\Models\NotificationQueueRepository`

| Method | Description |
|--------|-------------|
| `enqueue(int $deliveryId, string $channel, string $availableAt, int $maxAttempts = 3): int` | Insert a new queue item; return new ID |
| `fetchDue(string $now, int $limit = 50): array` | All items where `available_at <= now` and `attempts < max_attempts`, ordered by `available_at` ASC |
| `reschedule(int $id, int $attempts, string $availableAt): void` | Update `attempts` and `available_at` on a failed item for retry |
| `delete(int $id): void` | Remove a queue item (after successful delivery, skip, or exhaustion) |

---

## NotificationRepository

**Class:** `App\Modules\Notifications\Models\NotificationRepository`

| Method | Description |
|--------|-------------|
| `createNotification(array $data): int` | Insert module_notifications row; return ID |
| `createDelivery(array $data): int` | Insert module_notification_deliveries row (status='pending'); return ID |
| `updateDelivery(int $id, array $fields): void` | Update any subset of status/sent_at/read_at/error on a delivery row |
| `findDeliveryById(int $id): ?array` | Single delivery row with notification content JOINed; null if not found |
| `findByUser(int $userId, int $limit): array` | All sent in_app deliveries (read + unread) for a user, newest first |
| `findUnreadByUser(int $userId, int $limit): array` | Unread in_app deliveries for a user, newest first |
| `countUnreadByUser(int $userId): int` | Unread badge count (hot path — hit on every page load) |
| `markRead(int $deliveryId): void` | Set read_at = now; guarded by `read_at IS NULL` (idempotent) |
| `markAllReadByUser(int $userId): void` | Set read_at = now on all unread in_app deliveries for a user |
| `findBySource(string $type, int $id): array` | All notifications for a source entity (e.g. all generated by alert #3) |

---

## Browser UI — Notification Inbox

### Routes

```php
GET  /notifications               → NotificationController@index       [WebAuth]
POST /notifications/read-all      → NotificationController@markAllRead [WebAuth]
POST /notifications/{id}/read     → NotificationController@markRead    [WebAuth]
GET  /api/notifications/count     → NotificationController@unreadCount [SessionAuth]
GET  /api/notifications/recent    → NotificationController@recent      [SessionAuth]
```

### Topbar bell dropdown (primary UX)

The bell icon in the topbar is a Bootstrap dropdown button. When opened, it fetches `/api/notifications/recent` and renders the 10 most recent notifications in a panel.

**`GET /api/notifications/recent`** — Response:
```json
{
  "notifications": [
    {
      "id": 42,
      "title": "Device offline: Router A",
      "body":  "Router A (192.168.1.1) failed its reachability check.",
      "source_type": "alert",
      "created_at": "2026-04-19 14:00:00",
      "is_unread": true
    }
  ],
  "unread_count": 3
}
```

**Panel behaviour:**
- Unread items show a blue dot and the title at full opacity.
- Hovering an unread item reveals a checkmark "mark as read" button.
- Clicking it POSTs to `/notifications/{id}/read` with `Accept: application/json` — the controller returns `{"success": true, "unread_count": N}` and the panel updates in-place.
- "Mark all read" button (shown when unread > 0) POSTs to `/notifications/read-all` with `Accept: application/json` — returns `{"success": true, "unread_count": 0}`.
- A "View all notifications" link at the bottom opens the full inbox page.
- Unread badge in the topbar is updated live on every page load and after every mark-read action.

**Dual response mode for mark actions:**
`markRead()` and `markAllRead()` check the `Accept` header:
- `Accept: application/json` → JSON response (used by the dropdown)
- otherwise → `302 Location: /notifications` (used by the full inbox page form)

### Full inbox page (`GET /notifications`)

Renders all in_app deliveries for the current user (read + unread, newest first, limit 100).

Each card shows title, body, source badge, timestamp, and a "Mark read" button for unread items. The header shows unread count and a "Mark all as read" button.

The full inbox page is no longer linked from the sidebar. It is still accessible via the "View all notifications" link at the bottom of the topbar dropdown, and at the direct URL `/notifications`.

---

## NetMon Integration

### Wiring (`scripts/monitor.php`)

```php
$moduleNotifRepo = new ModuleNotificationRepository($db);
$queueRepo       = new NotificationQueueRepository($db);
$prefRepo        = new NotificationPreferenceRepository($db);
$notifService    = new NotificationService($moduleNotifRepo, $queueRepo, $prefRepo);
```

`dispatch()` calls enqueue delivery items into `module_notification_queue`.
Channel instances live only in `scripts/notify.php` (the worker).

### Wiring (`scripts/notify.php`)

```php
$moduleNotifRepo = new ModuleNotificationRepository($db);
$queueRepo       = new NotificationQueueRepository($db);
$notifModuleConfig = Config::load('notifications-module');

$channelRegistry = [
    'in_app' => new InAppChannel($moduleNotifRepo),
    'email'  => new EmailChannel($moduleNotifRepo, $notifModuleConfig['email'] ?? []),
];
```

### Dispatch policy

| Event | Channels | Dispatch condition |
|-------|----------|-------------------|
| Alert opened (`type='open'`) | `['in_app', 'email']` | First time this condition is detected |
| Alert re-confirmed (`type='reminder'`) | — | **Skipped** — no dispatch to avoid inbox/email flooding |
| Alert resolved | `['in_app', 'email']` | When device/service recovers |

### Events that dispatch

| Event | Title example |
|-------|--------------|
| Device offline (alert opened) | "Device offline: File Server" |
| Device recovered (alert resolved) | "Device recovered: File Server" |
| Service down (alert opened) | "Service down: Core Router / HTTPS" |
| Service recovered (alert resolved) | "Service restored: Core Router / HTTPS" |

The throttle (`alerts.last_notified_at`) controls the legacy log/webhook channels — it does not apply to module notification dispatch. The open/reminder distinction is the frequency control for in-app and email.

`dispatch()` filters recipients against `notification_preferences` before creating any delivery row. A user who has disabled the `email` channel will receive an in-app notification only; a user who has disabled both channels receives nothing from this event.

### Activating email in development

Add to `config/local.php`:

```php
return [
    'database' => [ 'driver' => 'sqlite' ],
    'notifications-module' => [
        'email' => [
            'enabled'      => true,
            'from_address' => 'netmon@localhost',
            'from_name'    => 'NetMon',
            'smtp_host'    => 'localhost',
            'smtp_port'    => 1025,    // e.g. Mailpit or Mailhog
            'smtp_user'    => '',
            'smtp_pass'    => '',
            'encryption'   => 'none',
        ],
    ],
];
```

---

## Notification Preferences

Per-user channel enable/disable flags are stored in the `notification_preferences` table (migration 0025).

**Schema:** `(user_id, channel)` composite PK — one row per user per channel. Missing row = enabled (opt-out model).

**Repository:** `App\Modules\Notifications\Models\NotificationPreferenceRepository`

| Method | Description |
|--------|-------------|
| `getAllForUser(int $userId): array` | Returns `['in_app' => bool, 'email' => bool]`; missing rows default to `true` |
| `isChannelEnabled(int $userId, string $channel): bool` | Single-channel check; default `true` |
| `setChannelEnabled(int $userId, string $channel, bool $enabled): void` | UPSERT for one channel |
| `saveAllForUser(int $userId, array $prefs): void` | UPSERT for multiple channels at once |

**UI:** The Profile page (`/profile#notification-preferences`) renders a form with two switches (in-app, email). Submits via `POST /profile/notification-preferences` to `ProfileController::saveNotificationPreferences()`.

**Delivery filtering:** Enforced at dispatch time in `NotificationService::dispatch()`. Before creating any delivery row or queue row for a given user/channel pair, the service calls `isChannelEnabled($userId, $channel)`. If the user has disabled that channel, no records are created — the worker never sees them. This keeps the worker simple and prevents stale records from accumulating in the DB for opted-out channels.

---

## What Remains

| Item | Notes |
|------|-------|
| SMS channel | Schema ready; implementation deferred |
| ~~Notification preferences~~ | Done — `notification_preferences` table + `NotificationPreferenceRepository` + Profile page UI |
| ~~Delivery filtering by preference~~ | Done — enforced at dispatch time in `NotificationService::dispatch()` before any row is created |
| ~~Async/queued delivery~~ | Done — `module_notification_queue` table + `NotificationQueueRepository` + `scripts/notify.php` worker |
| Per-event-type preferences | Currently a per-channel toggle only; a per-event-type matrix (e.g. "email only for offline, not for resolved") is deferred |
| Digest / batching | Group multiple events into one email |
| Rich HTML email templates | Plain text only for now |
| Admin delivery report | View of all deliveries across all users and channels |
| Email read tracking | Open-pixel tracking not planned; out of scope |

---

## Implementation Checklist

- [x] Migration `0022_create_module_notifications_table.php`
- [x] Migration `0023_create_module_notification_deliveries_table.php`
- [x] Migration `0024_create_module_notification_queue_table.php`
- [x] Migration `0025_create_notification_preferences_table.php`
- [x] `app/Modules/Notifications/Services/Channels/ChannelInterface.php`
- [x] `app/Modules/Notifications/Services/Channels/InAppChannel.php`
- [x] `app/Modules/Notifications/Services/Channels/SmtpMailer.php` — raw SMTP client
- [x] `app/Modules/Notifications/Services/Channels/EmailChannel.php` — full implementation
- [x] `config/notifications-module.php` — email/SMTP configuration
- [x] `app/Modules/Notifications/Models/NotificationRepository.php`
- [x] `app/Modules/Notifications/Models/NotificationPreferenceRepository.php` — per-user channel prefs
- [x] `app/Modules/Notifications/Models/NotificationQueueRepository.php` — queue CRUD
- [x] `app/Modules/Notifications/Services/NotificationService.php` — async dispatch (enqueues items)
- [x] `app/Modules/Notifications/Controllers/NotificationController.php` — inbox + recent() + AJAX mark-read
- [x] `app/Views/notifications/index.php` — full inbox page (view-all fallback)
- [x] Topbar bell — Bootstrap dropdown with AJAX recent panel; mark-read in-place
- [x] Routes: /notifications, /api/notifications/count, /api/notifications/recent
- [x] `scripts/monitor.php` — enqueues notifications; no channel registration needed
- [x] `scripts/notify.php` — queue worker with retry back-off; cron-friendly
- [x] Profile page (`/profile`) — account, notification preferences, API tokens
- [x] Delivery filtering by preference — enforced at dispatch time in `NotificationService::dispatch()`
- [ ] Per-event-type preference matrix — deferred
- [ ] SMS channel — deferred
- [ ] Admin view of all notification deliveries
