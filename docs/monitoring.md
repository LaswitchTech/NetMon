# Monitoring Subsystem

> **Status (Phase 10):** Device-level ICMP checks and service-level TCP checks are both implemented with full alert and notification integration. The device detail page now includes latency and status graphs rendered with Chart.js. HTTP checks, service CRUD UI, and retention policies are planned.
>
> For service-specific documentation see [services.md](services.md).
>
> Related: [services.md](services.md) · [devices.md](devices.md) · [domain-model.md](domain-model.md) · [schema.md](schema.md)

---

## Overview

The monitoring subsystem performs periodic reachability checks against active devices and records the results historically. The current implementation covers:

- Device-level checks only (ICMP ping)
- Result history in `device_checks` (append-only)
- Current status summary in `devices.status` and `devices.last_check_at`

---

## Architecture

```
scripts/monitor.php
    ↓  bootstrap (db + autoloader)

--- Device pass ---
DeviceCheckRepository::findMonitoringTargets()
    ↓  list of active devices with resolved target addresses
for each device:
    Pinger::check(target_address)
        ↓  exec(ping) → {status, latency_ms, message}
    DeviceCheckRepository::saveCheck()        → device_checks (append)
    DeviceCheckRepository::updateDeviceStatus() → devices.status + last_check_at
    AlertRepository::findOpenAlert / createAlert / incrementOccurrence / resolveAlert
    NotificationRepository::record + channels (throttled)

--- Service pass ---
ServiceCheckRepository::findServiceTargets()
    ↓  list of enabled services with resolved target addresses
for each service:
    TcpChecker::check(target_address, port)
        ↓  fsockopen() → {status, latency_ms, message}
    ServiceCheckRepository::saveCheck()       → service_checks (append)
    ServiceCheckRepository::updateServiceState() → monitored_services.last_state + last_check_at
```

See [services.md](services.md) for full service-pass documentation.

---

## File Structure

```
app/
    Models/
        DeviceCheckRepository.php     ← Device target selection + check persistence
        ServiceCheckRepository.php    ← Service target selection + check persistence
    Monitoring/
        Pinger.php                    ← ICMP check via exec(ping)
        TcpChecker.php                ← TCP connectivity check via fsockopen()

database/
    migrations/
        0014_create_device_checks_table.php
        0017_create_monitored_services_table.php
        0018_create_service_checks_table.php

scripts/
    monitor.php                       ← CLI monitoring runner (one-pass: devices then services)
```

---

## Database Schema

### `device_checks`

Append-only check result log. One row per device per monitoring pass.

| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| `id` | INTEGER PK | No | Auto-increment |
| `device_id` | INTEGER FK | No | → `devices.id` CASCADE DELETE |
| `checked_at` | VARCHAR(32) | No | ISO datetime when the check ran |
| `status` | VARCHAR(16) | No | `online`, `offline`, `timeout`, `error` |
| `latency_ms` | INTEGER | Yes | Round-trip time in ms; NULL if unreachable or errored |
| `message` | VARCHAR(255) | Yes | NULL on success; error detail on failure |
| `created_at` | VARCHAR(32) | No | Row insertion time |

**Indexes:**
- `device_checks_device_id` — fetch all checks for one device
- `device_checks_checked_at` — time-range queries across all devices
- `device_checks_device_id_checked_at` — per-device time-range (graph/history queries)

**Status values:**

| Value | Meaning |
|-------|---------|
| `online` | Host responded to ping |
| `offline` | Ping sent; host did not respond |
| `timeout` | (reserved) Explicit timeout distinct from network-down |
| `error` | Runner could not execute the check (e.g. exec() disabled) |

#### `device_checks` vs `devices.status`

| | `device_checks` | `devices.status` |
|-|-----------------|-----------------|
| **Purpose** | Historical log | Current summary |
| **Written by** | Monitoring runner (every pass) | Monitoring runner (every pass) |
| **Row count** | Grows unboundedly | One row per device |
| **Used for** | Graphs, uptime, trend analysis | UI badges, alert evaluation |
| **Source of truth** | Yes | No — derived from checks |

`devices.status` is a convenience cache. If it is ever out of sync with `device_checks`, the checks table is authoritative.

---

## Target Resolution

`DeviceCheckRepository::findMonitoringTargets()` resolves the address to check for each device:

```sql
SELECT
    d.id,
    d.name,
    COALESCE(
        (
            SELECT  da.address
            FROM    device_interfaces di
            JOIN    device_addresses  da ON da.interface_id = di.id
            WHERE   di.device_id    = d.id
              AND   di.is_management = 1
              AND   da.is_primary    = 1
            LIMIT 1
        ),
        NULLIF(TRIM(d.host), '')
    ) AS target_address,
    d.status AS current_status
FROM   devices d
WHERE  d.deleted_at IS NULL
  AND  d.status != 'disabled'
ORDER  BY d.name ASC
```

**Resolution order:**
1. Primary address (`is_primary = 1`) on the management interface (`is_management = 1`)
2. `devices.host` — fallback only, used if no interface/address record exists

**Excluded devices:**
- Soft-deleted (`deleted_at IS NOT NULL`)
- Status is `disabled` (reserved for future manual pause feature)
- `target_address` resolved to NULL or blank — runner logs a skip warning

---

## The Pinger

**Class:** `App\Monitoring\Pinger`

Wraps the OS `ping` binary via PHP's `exec()`. Handles three platforms:

| Platform | Command | Timeout flag |
|----------|---------|-------------|
| Linux | `ping -c 1 -W <sec>` | Seconds |
| macOS | `ping -c 1 -W <ms>` | Milliseconds |
| Windows | `ping -n 1 -w <ms>` | Milliseconds |

IPv6 addresses are detected by presence of `:` in the address string. For IPv6, `ping6` is tried first; `ping -6` is used as a fallback if `ping6` is not found.

RTT is extracted from the ping summary line using this pattern (average from min/avg/max):
```
rtt min/avg/max/mdev = 0.123/0.456/0.789/0.000 ms   ← Linux
round-trip min/avg/max/stddev = 0.123/...             ← macOS
```

If RTT cannot be extracted, the total wall-clock time of the `exec()` call is used.

### exec() unavailable

If `exec()` is listed in `php.ini disable_functions`, `Pinger::check()` returns:
```php
[
    'status'     => 'error',
    'latency_ms' => null,
    'message'    => 'exec() is disabled in php.ini; cannot run ping',
]
```

The runner records this as a check row with `status = 'error'` and does **not** update `devices.status`, leaving it unchanged. A warning line is printed to stdout.

---

## The Runner

**Script:** `scripts/monitor.php`

One-pass monitoring loop. Runs all active devices sequentially.

### Usage

```bash
# One pass (normal use)
php scripts/monitor.php

# Show per-device messages (e.g. error detail)
php scripts/monitor.php --verbose

# Resolve targets and print — no DB writes
php scripts/monitor.php --dry-run
```

### Scheduling with cron

Add to crontab to run every minute:

```
* * * * * php /path/to/netmon/scripts/monitor.php >> /path/to/netmon/storage/logs/monitor.log 2>&1
```

Ensure `storage/logs/` exists and is writable.

### Output

```
NetMon Monitor — 2026-04-14 10:23:45
--------------------------------------------------
Checking 3 device(s)...

  [OK]       Core Router              192.168.1.1          4 ms
  [OK]       Distribution Switch     192.168.1.2          2 ms
  [OFFLINE]  File Server              192.168.1.10         —

--------------------------------------------------
Done. 2 online, 1 offline. (0.8s)
```

### Status-to-device-status mapping

| `device_checks.status` | `devices.status` written |
|------------------------|--------------------------|
| `online` | `online` |
| `offline` | `offline` |
| `timeout` | `offline` |
| `error` | *(no update — status preserved as-is)* |

---

## DeviceCheckRepository

**Class:** `App\Models\DeviceCheckRepository`

| Method | Description |
|--------|-------------|
| `findMonitoringTargets(): array` | Return active devices with resolved target addresses |
| `findRecentByDevice(int $deviceId, int $limit = 50): array` | Return the most recent check rows for one device, newest first (for the history table) |
| `findHistoryByDevice(int $deviceId, int $limit = 100): array` | Return check history oldest-first for graph rendering |
| `saveCheck(array $check): int` | Append one check result row; return new row ID |
| `updateDeviceStatus(int $id, string $status, string $checkedAt): void` | Update `devices.status` and `devices.last_check_at` |

### `findRecentByDevice` vs `findHistoryByDevice`

| | `findRecentByDevice` | `findHistoryByDevice` |
|-|---------------------|----------------------|
| **Order** | DESC (newest first) | ASC (oldest first) |
| **Default limit** | 50 | 100 |
| **Columns** | id, checked_at, status, latency_ms, message | checked_at, status, latency_ms |
| **Use** | History table in the UI | Chart.js graph data |

Both query only `device_checks` — they do not touch summary columns.

---

## Visualization

The Device Detail page (`GET /devices/{id}`) includes a **Monitoring History** card above the check-history table. The card renders two graphs using **Chart.js 4** (loaded from CDN — no build step).

### Data preparation

`DeviceController::show()` calls `findHistoryByDevice($id, 100)` and passes the result as `$historySeries` to the view. In the view, PHP builds three parallel JavaScript arrays from the PHP array before `json_encode`:

| JS variable | Source | Usage |
|-------------|--------|-------|
| `labels` | `checked_at` | x-axis labels on both charts |
| `latency` | `latency_ms` | y-values on the latency chart (may be `null`) |
| `pointColors` | derived from `status` | per-point dot colors on the latency chart |
| `barColors` | derived from `status` | per-bar colors on the status timeline |

Status → color mapping:
- `online` → `rgba(25, 135, 84, …)` (Bootstrap green)
- anything else → `rgba(220, 53, 69, …)` (Bootstrap red)

### Charts

**Latency line chart** (`<canvas id="chart-latency">`, 180 px tall):
- Line chart, `spanGaps: false` — the line breaks where `latency_ms` is `null` (offline checks), making gaps visible
- Points are colored individually by status (green dot = online, red dot = offline)
- y-axis starts at zero; ticks show `ms` suffix
- x-axis shows up to 8 evenly-spaced labels (`maxTicksLimit: 8`) at 45° rotation

**Status timeline strip** (`<canvas id="chart-status">`, 40 px tall):
- Bar chart; all bars have value `1` (uniform height)
- Each bar's `backgroundColor` is set per-point from `barColors`
- Both axes hidden — the chart is read purely by color
- `barPercentage: 1.0` and `categoryPercentage: 1.0` produce a seamless colored strip

### Service-level charts

`DeviceController::show()` calls `findHistoryByService($serviceId, 100)` once per service attached to the device (keyed by service id into `$serviceHistories`). In the view, a PHP block computes `$serviceChartData` — same structure as the device data but using `status === 'up'` instead of `'online'` for the color mapping.

For each service with at least one check row, the view emits:
- A latency mini-chart (`<canvas id="chart-svc-latency-{id}">`, 110 px tall)
- A status strip (`<canvas id="chart-svc-status-{id}">`, 28 px tall)

These appear in a **Service History** card between the Monitored Services table and the device-level Monitoring History card.

### Integration

The `<script src="chart.js">` CDN tag and the inline initialization script are appended at the bottom of the `show.php` view fragment, inside the `<main>` element. They execute after all canvas elements are in the DOM.

The script block is guarded by `!empty($historySeries) || $hasServiceHistory` — Chart.js is loaded only when at least one chart dataset exists.

**Chart initialization is handled by two shared helper functions** defined once in the IIFE:

| Helper | Parameters | Reused by |
|--------|-----------|-----------|
| `makeLatencyChart(canvasId, labels, latency, pointColors, offlineLabel)` | Builds a line chart with per-point colors and null-gap breaks | Device chart + all service charts |
| `makeStatusChart(canvasId, labels, barColors, upLabel, downLabel)` | Builds a colored-bar status strip | Device chart + all service charts |

PHP emits one `makeLatencyChart()` + `makeStatusChart()` call pair per service inside a `<?php foreach ($serviceChartData …): ?>` loop, so adding more services requires no JS changes.

### Limitations

| Item | Notes |
|------|-------|
| No live updates | Page must be refreshed to see new checks |
| No aggregation | One point per check row — high-frequency polling will crowd the x-axis |
| No date-range control | Always shows the most recent 100 checks |
| N queries per page load | One `findHistoryByService()` query per service; acceptable for 2–5 services, but a single joined query would be more efficient at scale |
| CDN dependency | Chart.js loads from `cdn.jsdelivr.net`; the page degrades gracefully (no charts shown) if offline |

---

## Limitations (Phase 10)

| Limitation | Notes |
|------------|-------|
| TCP only | No UDP, HTTP, or HTTPS service checks. A `HttpChecker` class for HTTP/HTTPS is the most useful next protocol. |
| No scheduling | One pass per invocation. Continuous monitoring requires an external scheduler (cron). |
| Sequential | Both device and service checks run one at a time. A parallel runner is a future improvement. |
| exec() required for device checks | Environments where exec() is disabled will produce `error` results for all device checks. Service checks use `fsockopen()` and are unaffected. |
| No retention policy | ~~`device_checks` and `service_checks` grow unboundedly.~~ Retention cleanup implemented — see [Retention & Cleanup](#retention--cleanup). |
| `devices.last_check_at` transitional | Deprecated per the domain model. Still written for the device detail overview card. Will be removed once no consumers remain. |
| No aggregation on graphs | Each check row is one point — useful for low-frequency polling, noisy for high frequency. |

---

## Retention & Cleanup

`device_checks` and `service_checks` are append-only tables — every monitoring pass adds rows. Without periodic cleanup they grow unboundedly. `notification_history` has the same property. `scripts/cleanup.php` handles pruning all three tables.

### Configuration

Retention thresholds live in `config/monitoring.php` under the `retention` key. Override them per-environment in `config/local.php`:

```php
// config/local.php
return [
    'monitoring' => [
        'retention' => [
            'device_checks_days'        => 60,   // keep 60 days of device checks
            'service_checks_days'       => 60,   // keep 60 days of service checks
            'notification_history_days' => 180,  // keep 180 days of notification history
        ],
    ],
];
```

**Defaults (config/monitoring.php):**

| Key | Default | Table cleaned |
|-----|---------|---------------|
| `device_checks_days` | 30 | `device_checks` (cutoff: `checked_at`) |
| `service_checks_days` | 30 | `service_checks` (cutoff: `checked_at`) |
| `notification_history_days` | 90 | `notification_history` (cutoff: `created_at`) |

### Running cleanup

```bash
php scripts/cleanup.php
```

Example output:

```
── Cleanup Summary
Device checks deleted:  1243
Service checks deleted: 842
Notifications deleted:  211
```

The script is **idempotent** — running it multiple times in a row produces the same end state (rows already deleted are simply not found again).

### What cleanup never touches

- `alerts` — alert history is preserved regardless of retention settings
- `devices.status`, `devices.last_check_at` — summary columns are never modified
- `monitored_services.last_state`, `monitored_services.last_check_at` — same

### Repository methods

| Method | Table | Cutoff column |
|--------|-------|---------------|
| `DeviceCheckRepository::deleteOlderThan(\DateTime $cutoff): int` | `device_checks` | `checked_at` |
| `ServiceCheckRepository::deleteOlderThan(\DateTime $cutoff): int` | `service_checks` | `checked_at` |
| `NotificationRepository::deleteOlderThan(\DateTime $cutoff): int` | `notification_history` | `created_at` |

All three methods use indexed columns and return the count of deleted rows.

---

## What Remains

| Item | Dependency |
|------|-----------|
| ~~`monitored_services` table~~ | Done — migration 0017 |
| ~~`service_checks` table~~ | Done — migration 0018 |
| ~~Service-level TCP check runner~~ | Done — `TcpChecker` + service pass in `scripts/monitor.php` |
| ~~Service-level alerts~~ | Done — `service_down` alert type wired in Phase 9 |
| ~~Device history graphs~~ | Done — latency + status charts on device detail page (Phase 10) |
| ~~Service history graphs~~ | Done — per-service latency + status charts on device detail page (Phase 11) |
| Service CRUD UI | `monitored_services` rows currently created only via seed/SQL. |
| HTTP/HTTPS checks | `HttpChecker` class; status code validation. |
| UDP checks | Protocol-specific probes required; non-trivial. |
| Parallel runner | Performance improvement; not required for correctness. |
| ~~Check retention~~ | Done — `scripts/cleanup.php` with config-driven thresholds. |
