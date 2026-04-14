# Alerts

> **Status (Phase 10):** Device-level (`device_offline`) and service-level (`service_down`) alerts are both implemented with full notification integration. The browser UI now includes an alert detail page, service context in the list, and Acknowledge/Suppress actions. Resolved notifications and escalation are planned but not yet built.
>
> Related: [monitoring.md](monitoring.md) · [services.md](services.md) · [notifications.md](notifications.md) · [domain-model.md](domain-model.md) · [schema.md](schema.md)

---

## Overview

Alerts are stateful records that track an ongoing failure condition for a device (or, in the future, a specific service). Their core contract is:

> **There must never be more than one OPEN alert for the same (device_id, service_id, alert_type) tuple.**

When a failure is first detected, one alert row is created. If the same failure is detected again on the next monitoring pass, the existing alert is updated — not replaced. When the device recovers, the open alert is resolved. This means the alert table tells you *when* a problem started, *how many times* it was confirmed, and *when* it was resolved, without producing hundreds of duplicate rows.

---

## Alert Lifecycle

The same lifecycle applies to both device-level and service-level alerts.

```
Failure detected
    │
    ▼
findOpenAlert(device_id, service_id_or_null, alert_type)
    │
    ├─ Alert found (already open)
    │       → incrementOccurrence: last_seen_at = now, occurrence_count++
    │
    └─ No alert found
            → createAlert: status='open', first_seen_at=now, last_seen_at=now

Recovery detected
    │
    ▼
findOpenAlert(device_id, service_id_or_null, alert_type)
    │
    ├─ Alert found (open)
    │       → resolveAlert: status='resolved', resolved_at=now, last_seen_at=now
    │
    └─ No alert found
            → nothing to do
```

### State transitions

```
             ┌─────────────────────────┐
             │                         ▼
[ open ] ──→ [ acknowledged ] ──→ [ resolved ]
   │                                   ▲
   └──────────────────────────────────►┘ (direct resolve on recovery)
   │
   └──→ [ suppressed ]
```

| Transition | Trigger | Who |
|------------|---------|-----|
| → `open` | First failed check for (device, type) | Monitoring runner |
| → `open` (re-confirmed) | Subsequent failures while already open | Monitoring runner (increments count) |
| → `resolved` | Device comes back online | Monitoring runner |
| → `acknowledged` | Admin marks it seen | Future UI |
| → `suppressed` | Admin silences it | Future UI |

**Important:** `acknowledged` is not the same as `resolved`. An acknowledged alert is still open — the admin has seen it but the device is still offline. It will not re-alert until the condition changes.

---

## Deduplication Strategy

Deduplication is enforced in `AlertRepository::findOpenAlert()` before every `createAlert()` call. The monitoring runner follows this invariant strictly:

```php
$openAlert = $alertRepo->findOpenAlert($deviceId, null, 'device_offline');

if ($openAlert !== null) {
    $alertRepo->incrementOccurrence($openAlert['id'], $timestamp);
} else {
    $alertRepo->createAlert([...]);
}
```

The query for the deduplication check:

```sql
SELECT * FROM alerts
WHERE  device_id  = ?
  AND  service_id IS NULL        -- NULL handled explicitly (SQL NULL != NULL)
  AND  alert_type = ?
  AND  status     = 'open'
LIMIT 1
```

The `alerts_open_lookup` index on `(device_id, service_id, alert_type, status)` makes this a fast indexed lookup on every monitoring pass, regardless of how many historical alerts exist in the table.

**Why application-level enforcement rather than a DB unique constraint?**

A standard UNIQUE constraint would cover `(device_id, service_id, alert_type)` but cannot express "unique where status = 'open'". SQLite supports partial indexes (`WHERE status = 'open'`) but MySQL does not (before 8.0). For now, application logic enforces the invariant; a SQLite partial index can be added as an optional fast-fail guard once the codebase matures.

---

## Schema

### `alerts`

| Column | Type | Nullable | Default | Notes |
|--------|------|----------|---------|-------|
| `id` | INTEGER PK | No | — | Auto-increment |
| `device_id` | INTEGER FK | No | — | → `devices.id` CASCADE DELETE |
| `service_id` | INTEGER | Yes | NULL | → `monitored_services.id` (future FK, table not yet created) |
| `alert_type` | VARCHAR(64) | No | — | Machine-readable label: `device_offline`, `service_down`, etc. |
| `status` | VARCHAR(16) | No | `open` | `open`, `acknowledged`, `resolved`, `suppressed` |
| `first_seen_at` | VARCHAR(32) | No | — | When the failure was first detected |
| `last_seen_at` | VARCHAR(32) | No | — | When the failure was last confirmed (updated on re-confirmation and on resolve) |
| `last_notified_at` | VARCHAR(32) | Yes | NULL | When the last notification was sent for this alert. Updated by the monitoring runner after each dispatch; drives the 15-minute throttle. |
| `occurrence_count` | INTEGER | No | `1` | How many consecutive check passes confirmed this failure |
| `resolved_at` | VARCHAR(32) | Yes | NULL | When the condition cleared. NULL = still open. |
| `created_at` | VARCHAR(32) | No | — | Row insertion time |

**Indexes:**
- `alerts_device_id` — all alerts for a given device
- `alerts_status` — all open alerts (dashboard)
- `alerts_open_lookup (device_id, service_id, alert_type, status)` — deduplication lookup (hot path)

**Migration 0015.**

---

## AlertRepository

**Class:** `App\Models\AlertRepository`

| Method | Description |
|--------|-------------|
| `findOpenAlert(int $deviceId, ?int $serviceId, string $type): ?array` | Deduplication check: is there already an open alert? |
| `findAllOpen(): array` | All open alerts with device + service context (JOINs devices + monitored_services) |
| `findRecent(int $limit = 100): array` | Most recent N alerts of any status with device + service context |
| `findById(int $id): ?array` | Single alert by ID with device + service context; null if not found |
| `findByDevice(int $deviceId): array` | All alerts for a specific device with service context |
| `createAlert(array $data): int` | Insert a new open alert row; return new ID |
| `updateAlert(int $alertId, array $fields): void` | Generic field update (e.g. `last_notified_at` when notifications are added) |
| `acknowledge(int $id): void` | Set status='acknowledged'; guarded by AND status='open' (idempotent) |
| `suppress(int $id): void` | Set status='suppressed'; guarded by AND status='open' (idempotent) |
| `resolveAlert(int $alertId, string $resolvedAt): void` | Set status='resolved'; set resolved_at and last_seen_at; idempotent |
| `incrementOccurrence(int $alertId, string $lastSeenAt): void` | occurrence_count++ and update last_seen_at |
| `touchLastSeen(int $alertId, string $lastSeenAt): void` | Update last_seen_at only (no count change) |

`findOpenAlert` uses explicit `IS NULL` vs `= ?` branches for `service_id` to avoid the `NULL = NULL` false negative that SQL's `= ?` binding would produce.

---

## Integration with the Monitoring Runner

**Script:** `scripts/monitor.php`

Alert logic runs inside both the device and service check loops. It is skipped when the check status is `'error'` (the runner itself failed — not an actual device/service failure).

### Device pass

```
For each device:
    1. Pinger::check(target_address)
    2. DeviceCheckRepository::saveCheck()
    3. DeviceCheckRepository::updateDeviceStatus()
    4. Alert logic → sets $pendingNotification:
         if deviceStatus == 'offline':
             findOpenAlert(device_id, NULL, 'device_offline') → create or increment
         if deviceStatus == 'online':
             findOpenAlert(device_id, NULL, 'device_offline') → resolve if present
    5. Notification block:
         if $pendingNotification set AND channels configured AND throttle elapsed:
             dispatch → record in notification_history → update last_notified_at
```

### Service pass

```
For each service:
    1. TcpChecker::check(target_address, port)
    2. ServiceCheckRepository::saveCheck()
    3. ServiceCheckRepository::updateServiceState()
    4. Alert logic → sets $svcPendingNotification:
         if svcResult == 'down':
             findOpenAlert(device_id, service_id, 'service_down') → create or increment
         if svcResult == 'up':
             findOpenAlert(device_id, service_id, 'service_down') → resolve if present
    5. Notification block:
         if $svcPendingNotification set AND channels configured AND throttle elapsed:
             dispatch → record in notification_history → update last_notified_at
```

**Dry-run mode** (`--dry-run`) skips all DB writes including alert and notification operations.

**Verbose mode** (`--verbose`) prints alert and notification events as indented sub-lines:
```
  [OFFLINE]  File Server              192.168.1.10         —
             ↳ alert #3 opened: device_offline
             ↳ notify [log] ✓ open

  [DOWN]   File Server / SSH          192.168.1.10:22      —
             ↳ alert #7 opened: service_down
             ↳ notify [log] ✓ open

  [UP]     File Server / SSH          192.168.1.10:22      8 ms
             ↳ alert #7 resolved (service back up)
```

---

## Current Alert Types

| Type | `service_id` | Trigger | Phase added |
|------|-------------|---------|-------------|
| `device_offline` | NULL | Device fails ICMP ping check | Phase 6 |
| `service_down` | set | Service TCP check returns `down` | Phase 9 |

**`device_offline`** uses `service_id = NULL` — it is a device-level condition, not tied to any particular service. `findOpenAlert()` uses an explicit `IS NULL` branch for this lookup.

**`service_down`** uses a non-null `service_id` pointing to the `monitored_services` row. Each enabled service gets its own independent alert lifecycle. A device with three monitored services can have up to three simultaneous open `service_down` alerts.

---

## Browser UI

### Alerts list

**Route:** `GET /alerts[?filter=open|all]` (requires session authentication)

**Controller:** `App\NetMon\Controllers\AlertController::index()`

The Alerts page is reachable from the sidebar navigation.

#### Filter toggle

| URL | Repository method | Shows |
|-----|------------------|-------|
| `/alerts` or `/alerts?filter=open` | `findAllOpen()` | All currently open alerts, newest `last_seen_at` first |
| `/alerts?filter=all` | `findRecent(100)` | The 100 most recent alerts of any status |

#### Columns

| Column | Source | Notes |
|--------|--------|-------|
| Device | `alerts.device_id` JOIN `devices.name` | Linked to `/alerts/{id}`; falls back to `#id` |
| Type | `alerts.alert_type` | Formatted: `device_offline` → "Device offline" |
| Service | `alerts.service_id` JOIN `monitored_services` | Name and port, or "—" for device-level alerts |
| Status | `alerts.status` | Badge: Open (red), Resolved (green), Ack'd (yellow), Suppressed (grey) |
| Count | `alerts.occurrence_count` | How many consecutive check passes confirmed the failure |
| First seen | `alerts.first_seen_at` | When the condition was first detected |
| Last seen | `alerts.last_seen_at` | When the condition was last confirmed |
| Last notified | `alerts.last_notified_at` | "Never" if no notification has been sent |

#### Empty states

- `filter=open`: "No open alerts — all devices are healthy."
- `filter=all`: "No alerts recorded yet."

---

### Alert detail page

**Route:** `GET /alerts/{id}` (requires session authentication)

**Controller:** `App\NetMon\Controllers\AlertController::show()`

Shows full context for a single alert including device link, service context (if applicable), all timestamps, occurrence count, and notification history.

#### Actions (open alerts only)

| Action | Route | Controller method | Effect |
|--------|-------|-------------------|--------|
| Acknowledge | `POST /alerts/{id}/acknowledge` | `AlertController::acknowledge()` | Sets status=`acknowledged` |
| Suppress | `POST /alerts/{id}/suppress` | `AlertController::suppress()` | Sets status=`suppressed` |

Both actions redirect back to the alert detail page after the transition. Both are guarded by `AND status = 'open'` in the repository layer — submitting the form on an already-transitioned alert is safe and has no effect.

#### Notification history table

Columns: Sent at, Channel, Type, Status (badge), Message. Sourced from `NotificationRepository::findRecentByAlert()` (most recent 20 rows).

---

## What Remains

| Item | Notes |
|------|-------|
| ~~Service-level alerts~~ | Done — `service_down` alert type wired in Phase 9 |
| ~~`acknowledged` / `suppressed` UI~~ | Done — POST routes and detail page implemented in Phase 10 |
| ~~Alert detail view~~ | Done — `/alerts/{id}` with overview, actions, and notification history |
| ~~Alerts UI — service column~~ | Done — Service column added to list; device name links to detail page |
| `resolved` notification | Alerts resolve silently; no notification dispatched on recovery |
| Service name in notification payload | Channels currently receive device context only; service name / port not surfaced |
| Email channel | Requires SMTP configuration; not yet built. See [notifications.md](notifications.md). |
| Escalation | Defined in domain model but not yet designed in detail |
