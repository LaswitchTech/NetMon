# Monitored Services

> **Status (Phase 11):** Service-level monitoring, `service_down` alerts, and per-service history graphs are implemented.
> TCP checks run alongside device-level ICMP checks. Check history is stored and visualized on the device detail page.
> Alerts are deduplicated and notifications flow through the existing channel infrastructure.
> Service CRUD UI and richer protocol checks are planned.
>
> Related: [monitoring.md](monitoring.md) · [alerts.md](alerts.md) · [devices.md](devices.md) · [domain-model.md](domain-model.md)

---

## Overview

A **monitored service** defines one TCP port to check on a device. Services are
first-class entities: they have their own history table, their own state summary,
and will eventually have their own alert lifecycle.

In Phase 8:
- Services are stored in `monitored_services` (configured per device)
- Check results are stored in `service_checks` (append-only, time-series)
- The monitoring runner performs TCP connectivity checks for all enabled services
- The device detail page displays each service's current state

---

## File Structure

```
database/
    migrations/
        0017_create_monitored_services_table.php   ← Schema: monitored_services
        0018_create_service_checks_table.php       ← Schema: service_checks
    seeds/
        MonitoredServiceSeed.php                   ← Sample services for dev (3 devices)

app/
    Models/
        ServiceCheckRepository.php                 ← Target selection, check persistence, state update
    Monitoring/
        TcpChecker.php                             ← TCP connectivity check via fsockopen()
```

---

## Database Schema

### `monitored_services`

One row per service to check on a device. Stores configuration + cached current state.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | INTEGER PK | No | — | Auto-increment |
| `device_id` | INTEGER FK | No | — | → `devices.id` CASCADE DELETE |
| `name` | VARCHAR(128) | No | — | Human label (e.g. `SSH`, `HTTPS`, `SMB`) |
| `protocol` | VARCHAR(16) | No | `tcp` | Protocol: `tcp` (Phase 8 only). Future: `udp`, `http`, `https` |
| `port` | INTEGER | No | — | TCP port number (1–65535) |
| `monitoring_enabled` | INTEGER | No | `1` | 0 = paused / disabled |
| `expected_state` | VARCHAR(16) | No | `up` | What a healthy check should return. Currently `up` |
| `last_state` | VARCHAR(16) | Yes | NULL | Cached result of the most recent check: `up`, `down`, `error`, or NULL (never checked) |
| `last_check_at` | VARCHAR(32) | Yes | NULL | ISO datetime of the most recent check (NULL until first check) |
| `created_at` | VARCHAR(32) | No | — | Record creation datetime |

**Indexes:**
- `monitored_services_device_id` — load all services for one device
- `monitored_services_monitoring_enabled` — select enabled services during target resolution

**Note on `last_state` / `last_check_at`:**
These are summary columns — they cache the most recent `service_checks` row so the UI
can display current state without a JOIN. They are updated by the monitoring runner
after every successful check. If the check runner itself fails (`status = 'error'`),
these columns are left unchanged to preserve the last known good state.

---

### `service_checks`

Append-only historical log of service check results. One row per service per monitoring pass.

| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| `id` | INTEGER PK | No | Auto-increment |
| `service_id` | INTEGER FK | No | → `monitored_services.id` CASCADE DELETE |
| `checked_at` | VARCHAR(32) | No | ISO datetime when the check ran |
| `status` | VARCHAR(16) | No | `up`, `down`, `error` |
| `latency_ms` | INTEGER | Yes | TCP handshake time in ms. NULL if unreachable or error |
| `message` | VARCHAR(255) | Yes | NULL on success; error detail on failure |
| `created_at` | VARCHAR(32) | No | Row insertion time |

**Status values:**

| Value | Meaning |
|-------|---------|
| `up` | TCP connection established and closed cleanly |
| `down` | Connection refused, timed out, or host unreachable |
| `error` | Check could not run (invalid port, no target address) |

**Indexes:**
- `service_checks_service_id` — fetch check history for one service
- `service_checks_checked_at` — time-range queries across all services
- `service_checks_service_id_checked_at` — per-service time-range (history graphs)

---

## ServiceCheckRepository

**Class:** `App\Models\ServiceCheckRepository`

| Method | Description |
|--------|-------------|
| `findServiceTargets(): array` | Return all enabled services for active devices, with resolved target addresses |
| `findByDevice(int $deviceId): array` | Return all services for one device (UI read, includes disabled services) |
| `findHistoryByService(int $serviceId, int $limit = 100): array` | Return check history for one service ordered ASC (for graph rendering) |
| `saveCheck(array $check): int` | Append one check result row to `service_checks`; return new row ID |
| `updateServiceState(int $serviceId, string $state, string $checkedAt): void` | Update `last_state` and `last_check_at` on a `monitored_services` row |

### `findServiceTargets()` — address resolution

Uses the same management-interface preference as `DeviceCheckRepository::findMonitoringTargets()`:

```sql
SELECT
    ms.id AS service_id, ms.name AS service_name, ms.protocol, ms.port,
    d.id  AS device_id,  d.name  AS device_name,
    COALESCE(
        (SELECT da.address FROM device_interfaces di
         JOIN device_addresses da ON da.interface_id = di.id
         WHERE di.device_id = d.id AND di.is_management = 1 AND da.is_primary = 1
         LIMIT 1),
        NULLIF(TRIM(d.host), '')
    ) AS target_address
FROM monitored_services ms
JOIN devices d ON d.id = ms.device_id
WHERE ms.monitoring_enabled = 1
  AND d.deleted_at IS NULL
  AND d.status != 'disabled'
ORDER BY d.name ASC, ms.name ASC
```

---

## TcpChecker

**Class:** `App\Monitoring\TcpChecker`

Wraps PHP's `fsockopen()` for a blocking TCP connection attempt. Does not
require `exec()` or elevated privileges. Returns a result array compatible
with `ServiceCheckRepository::saveCheck()`.

```php
public function check(string $address, int $port, int $timeoutSeconds = 3): array
```

Returns:
```php
[
    'status'     => 'up'|'down'|'error',
    'latency_ms' => int|null,   // NULL if connection failed
    'message'    => string|null,
]
```

**Timeout behaviour:** `fsockopen()` blocks for up to `$timeoutSeconds`. For
hosts behind firewalls that silently drop packets (rather than sending RST),
the check will take the full timeout. Hosts that actively refuse the connection
fail immediately with `ECONNREFUSED`.

**Latency:** Measures TCP handshake time (wall-clock from call start to
connection established), not application response time.

---

## Monitoring Runner Integration

`scripts/monitor.php` runs a **service check pass** after the device check pass.

### Service pass flow

```
ServiceCheckRepository::findServiceTargets()
    ↓  list of enabled services with resolved target addresses
for each service:
    TcpChecker::check(target_address, port)
        ↓  {status, latency_ms, message}
    ServiceCheckRepository::saveCheck()   → service_checks (append)
    if status != 'error':
        ServiceCheckRepository::updateServiceState() → monitored_services.last_state + last_check_at
```

### Output format

```
NetMon Monitor — 2026-04-14 10:23:45
--------------------------------------------------
Checking 3 device(s)...

  [OK]       Core Router              192.168.1.1          4 ms
  [OK]       Distribution Switch      192.168.1.2          2 ms
  [OFFLINE]  File Server              192.168.1.10         —

Checking 7 service(s)...

  [UP]     Core Router / HTTPS          192.168.1.1:443     12 ms
  [UP]     Core Router / SSH            192.168.1.1:22       5 ms
  [DOWN]   Distribution Switch / HTTP   192.168.1.2:80      —       (Connection refused)
  [DOWN]   File Server / NFS            192.168.1.10:2049   —       (Connection timed out)
  ...

--------------------------------------------------
Done. 2 online, 1 offline. (1.4s)
Services: 3 up, 4 down.
```

---

## Device Detail Page

The device detail page (`GET /devices/{id}`) includes:

### Monitored Services table

A read-only table listing all configured services and their current state.

Variables:
- `$services` — from `ServiceCheckRepository::findByDevice()`

Each row shows: name, protocol/port, monitoring enabled/disabled badge, current state badge (`last_state`), and last check timestamp.

### Service History graphs

A **Service History** card below the Monitored Services table. For each service with at least one recorded check, the card renders:

- A **latency mini-chart** (line chart, 110 px; `spanGaps: false` breaks the line where `latency_ms` is `null`)
- A **status strip** (colored bar chart, 28 px; green = `up`, red = `down`)

**Data loading:** `DeviceController::show()` calls `ServiceCheckRepository::findHistoryByService($serviceId, 100)` once per service, building a `$serviceHistories` map (keyed by service id). Each value is an array of `{checked_at, status, latency_ms}` rows ordered ASC.

**Chart rendering:** The view converts each service's raw history into per-point color arrays and emits the data as inline JSON. Chart.js `makeLatencyChart()` and `makeStatusChart()` helper functions (shared with the device-level charts) initialize the canvases.

Canvas IDs are namespaced to avoid collisions:
- `chart-svc-latency-{serviceId}`
- `chart-svc-status-{serviceId}`

The card is omitted entirely if no service has any check history.

---

## Seeding Sample Services (Development)

`database/seeds/MonitoredServiceSeed.php` attaches representative services to
the three devices from `DeviceSeed`:

| Device | Services |
|--------|----------|
| Core Router | SSH (22), HTTPS (443) |
| Distribution Switch | SSH (22), HTTP (80) |
| File Server | SSH (22), SMB (445), NFS (2049) |

Run after `DeviceSeed`:
```bash
php scripts/seed.php DeviceSeed
php scripts/seed.php MonitoredServiceSeed
```

Both seeds are idempotent and safe to re-run.

---

## Alert Generation

When a service check returns `'down'`, the monitoring runner creates or updates a
`service_down` alert using the same stateful deduplication rules as device alerts:

```
Service check → 'down'
    findOpenAlert(device_id, service_id, 'service_down')
        ├── found  → incrementOccurrence + reminder notification (if throttle elapsed)
        └── not found → createAlert + open notification

Service check → 'up'
    findOpenAlert(device_id, service_id, 'service_down')
        ├── found  → resolveAlert (silent — no resolved notification yet)
        └── not found → nothing to do
```

Service check → `'error'`: alert logic is skipped entirely. `last_state` is also
preserved unchanged in this case.

Each enabled service has an independent alert lifecycle. A device with three monitored
services can have up to three simultaneous open `service_down` alerts.

Notifications flow through the same log/webhook channels as device alerts. The
channel receives standard device context (`id`, `name`, `target_address`); the
`alert_type = 'service_down'` field in the alert row identifies the nature of the event.

See [alerts.md](alerts.md) for the full alert lifecycle and deduplication design.

---

## What Remains Before Service CRUD and Richer Checks

| Item | Notes |
|------|-------|
| ~~Service-level alerts~~ | Done — `service_down` wired in Phase 9 |
| ~~Service notifications~~ | Done — existing log/webhook channels triggered automatically |
| ~~Service uptime graphs~~ | Done — latency + status charts on device detail page (Phase 11) |
| Service CRUD UI | `monitored_services` rows currently created only via seed/SQL. |
| Service name in notification payload | Channels receive device context only; service name/port not yet surfaced. |
| HTTP/HTTPS checks | `HttpChecker` class; HTTP status code validation; redirect handling. |
| UDP checks | Non-trivial — UDP has no connection concept; requires protocol-specific probes. |
| Check retention policy | `service_checks` grows unboundedly; add purge or cap per service. |
