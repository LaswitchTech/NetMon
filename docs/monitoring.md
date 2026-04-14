# Monitoring Subsystem

> **Status (Phase 5):** Device-level ICMP reachability checks are implemented. Service-level checks (ports, HTTP, etc.) are planned but not yet built. Alert generation and notifications are not yet connected to the monitoring runner.
>
> Related: [devices.md](devices.md) · [domain-model.md](domain-model.md) · [schema.md](schema.md)

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
DeviceCheckRepository::findMonitoringTargets()
    ↓  list of active devices with resolved target addresses
for each device:
    Pinger::check(target_address)
        ↓  exec(ping) → {status, latency_ms, message}
    DeviceCheckRepository::saveCheck()   → device_checks (append)
    DeviceCheckRepository::updateDeviceStatus() → devices.status + last_check_at
```

---

## File Structure

```
app/
    Models/
        DeviceCheckRepository.php     ← Target selection + check persistence
    Monitoring/
        Pinger.php                    ← ICMP check via exec(ping)

database/
    migrations/
        0014_create_device_checks_table.php

scripts/
    monitor.php                       ← CLI monitoring runner (one-pass)
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
| `saveCheck(array $check): int` | Append one check result row; return new row ID |
| `updateDeviceStatus(int $id, string $status, string $checkedAt): void` | Update `devices.status` and `devices.last_check_at` |

---

## Limitations (Phase 5)

| Limitation | Notes |
|------------|-------|
| ICMP only | No TCP/UDP/HTTP checks. Service-level checks require `monitored_services` table (not yet built). |
| No alerts | Check results are stored and device status is updated, but no alert rows are created or notifications sent. |
| No scheduling | One pass per invocation. Continuous monitoring requires an external scheduler (cron). |
| Sequential | Devices are checked one at a time. A parallel runner (using pcntl_fork or a job queue) is a future improvement. |
| exec() required | Environments where exec() is disabled in php.ini will produce `error` check results for all devices. |
| No retention policy | `device_checks` grows unboundedly. A cleanup job (e.g. purge rows older than 30 days) should be added before running continuously in production. |
| `devices.last_check_at` transitional | This column is deprecated per the domain model. It is still written during Phase 5 for UI compatibility. It will be superseded by querying `device_checks` directly once the UI is updated to read history. |

---

## What Remains Before Service-Level Monitoring

| Item | Dependency |
|------|-----------|
| `monitored_services` table | Defines per-device services (TCP port, HTTP URL, etc.) |
| `service_checks` table | Stores per-service check history |
| Service-level check runner | Extends `scripts/monitor.php` to loop over services per device |
| Alert generation | Needs `alerts` table; deduplication logic in the runner |
| Notifications | Needs `notification_history` table + email/webhook dispatch |
| Parallel runner | Performance improvement; not required for correctness |
| Check retention | Purge or cap old `device_checks` rows |
