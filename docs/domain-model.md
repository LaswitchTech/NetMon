# NetMon Domain Model Roadmap

> **Status:** Living planning document.
> - **Phase 1 complete:** `merged_into_device_id` and `deleted_at` added to `devices` (migration 0010).
> - **Phase 2 complete:** `device_interfaces` and `device_addresses` implemented (migrations 0011–0013).
> - **Phase 3 complete:** `DeviceRepository` read path queries `device_addresses` with `devices.host` fallback.
> - **Phase 4 complete:** Device CRUD (create/edit/soft-delete) writes to `device_interfaces` and `device_addresses`; keeps `devices.host` in sync.
> - **Phase 5 complete:** `device_checks` table (migration 0014) and CLI monitoring runner (`scripts/monitor.php`) — device-level ICMP reachability checks with historical storage. `device_checks` is device-level only and coexists with `service_checks`.
> - **Phase 6 complete:** `alerts` table (migration 0015) and stateful alert engine integrated into `scripts/monitor.php`. Device-level `device_offline` alerts with full deduplication. See [alerts.md](alerts.md).
> - **Phase 7 complete:** `notification_history` table (migration 0016) and notifications engine integrated into `scripts/monitor.php`. Log and webhook channels, 15-minute throttle, `open`/`reminder` notification types. See [notifications.md](notifications.md).
> - **Phase 8 complete:** `monitored_services` (migration 0017) and `service_checks` (migration 0018) implemented. `TcpChecker` added. Service-level TCP checks integrated into the monitoring runner. Device detail page shows services and their current state. See [services.md](services.md).
> - **Phase 9 complete:** `service_down` alert type wired into the monitoring runner's service pass. Identical deduplication and notification logic as `device_offline`. Notifications flow through existing log/webhook channels automatically. See [alerts.md](alerts.md).
> - **Phase 10 complete:** Discovery subsystem (`discovery_jobs`, `discovery_findings` tables — migrations 0019–0020), subnet scanner, ARP resolver, enrichment, operator action workflow (link / create device / ignore), and duplicate/match suggestion system. See [discovery.md](discovery.md) and [devices.md](devices.md).
> - All subsequent phases are planned but not yet implemented.
>
> Related: [schema.md](schema.md) · [devices.md](devices.md) · [monitoring.md](monitoring.md) · [architecture.md](architecture.md)

---

## Design Goals

1. **A device is a logical entity, not an IP address.** IPs are how you reach a device at a given moment — not its identity. The model must support devices with multiple interfaces and multiple addresses per interface.

2. **No duplicate open alerts.** For a given (device, service, alert type), exactly one alert row may be `open` at a time. Subsequent failures update the existing alert rather than creating new rows.

3. **Reminder notifications are modeled on the alert row, not as separate events.** A single `last_notified_at` timestamp on the alert record drives 15-minute repeat notifications. The monitoring runner checks this timestamp on every cycle.

4. **Discovery is a proposal, not an assertion.** Subnet scan findings are staged as `discovery_findings` rows. They are matched (auto or manually) to existing devices. Unmatched findings queue for human review.

5. **Merges are soft.** When two device records are discovered to represent the same physical host, one survives and the other is soft-deleted with `merged_into_device_id` pointing to the canonical record. All history is preserved.

---

## Current Implemented Schema (Phase 1–10)

These tables exist in the database today.

```
migrations
users ──< user_groups >── groups ──< group_permissions >── permissions
users ──< api_tokens
devices ──< device_interfaces ──< device_addresses
devices ──< device_checks
devices ──< monitored_services ──< service_checks
devices ──< alerts ──< notification_history
discovery_jobs ──< discovery_findings ──→ devices (matched_device_id, nullable)
```

| Table | Status |
|-------|--------|
| `migrations` | Implemented |
| `users` | Implemented |
| `groups` | Implemented |
| `permissions` | Implemented |
| `user_groups` | Implemented |
| `group_permissions` | Implemented |
| `api_tokens` | Implemented |
| `devices` | Implemented — includes soft-delete columns; write path uses new schema |
| `device_interfaces` | Implemented — migration 0011 |
| `device_addresses` | Implemented — migration 0012 |
| `device_checks` | Implemented — migration 0014 (Phase 5: device-level check history) |
| `alerts` | Implemented — migration 0015 (Phase 6: stateful alert engine) |
| `notification_history` | Implemented — migration 0016 (Phase 7: alert notification dispatch log) |
| `monitored_services` | Implemented — migration 0017 (Phase 8: service configuration per device) |
| `service_checks` | Implemented — migration 0018 (Phase 8: service-level check history) |
| `discovery_jobs` | Implemented — migration 0019 (Phase 10: discovery job configuration) |
| `discovery_findings` | Implemented — migration 0020 (Phase 10: per-IP discovered hosts) |

### Planned tables (not yet implemented)

| Table | Migration | Purpose | Design doc |
|-------|-----------|---------|------------|
| `notes` | 0021 | Polymorphic entity annotations (device, alert, finding, etc.) | [notes-module.md](notes-module.md) |
| `module_notifications` | 0022 | Notification events (title, body, source context) | [notifications-module.md](notifications-module.md) |
| `module_notification_deliveries` | 0023 | Per-user per-channel delivery records and inbox state | [notifications-module.md](notifications-module.md) |

**Transitional dual-source state:** `devices.host` and `device_addresses` both contain the same host/IP. The read path and write path both use `device_addresses` (with `devices.host` as a fallback/sync target). `devices.host` can be dropped once the monitoring runner no longer needs it — currently it is kept in sync but not used as the primary address source. See [Migration Path](#migration-path-v1-→-full-model).

---

## Target Domain Model (Full)

### Entity-Relationship Overview

```
devices ──< device_interfaces ──< device_addresses
   │
   └──< monitored_services ──< service_checks
   │
   └──< alerts ──< notification_history
   │
   └── merged_into_device_id (self-reference, nullable)

discovery_jobs ──< discovery_findings ──→ devices (merged_device_id, nullable)
```

---

## Entity Definitions

### `devices` (extended)

The logical identity anchor for a network host. One row per managed device regardless of how many IPs or interfaces it has.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | INTEGER PK | No | — | Auto-increment |
| `name` | VARCHAR(128) | No | — | Human-readable label (e.g. "Core Router") |
| `host` | VARCHAR(255) | No | — | **v1 transitional — deprecated.** Primary host/IP. Superseded by `device_addresses`. |
| `status` | VARCHAR(32) | No | `unknown` | Cached aggregate status. Updated by monitoring runner after each cycle. Values: `online`, `offline`, `degraded`, `unknown` |
| `last_check_at` | VARCHAR(32) | Yes | NULL | **v1 transitional — deprecated.** Superseded by `service_checks.checked_at`. |
| `merged_into_device_id` | INTEGER FK | Yes | NULL | If non-null, this record has been merged into another device. Soft delete. |
| `deleted_at` | VARCHAR(32) | Yes | NULL | Soft delete timestamp. Set when merged. NULL = active record. |
| `created_at` | VARCHAR(32) | No | — | Record creation datetime |

**Added by migration 0010.** ✓ Implemented.

Status transitions driven by the monitoring runner:
- `online` — all monitored services responding
- `degraded` — at least one service failing, not all
- `offline` — all services failing, or ICMP unreachable
- `unknown` — no checks have run yet

---

### `device_interfaces`

One row per network interface on a device. An interface groups addresses and carries physical-layer metadata.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | INTEGER PK | No | — | Auto-increment |
| `device_id` | INTEGER FK | No | — | → `devices.id` CASCADE DELETE |
| `name` | VARCHAR(64) | No | — | Interface name (e.g. `eth0`, `WAN`, `Management`) |
| `mac_address` | VARCHAR(17) | Yes | NULL | MAC address in `AA:BB:CC:DD:EE:FF` format. NULL if unknown. |
| `is_management` | INTEGER | No | `0` | 1 = preferred interface for device-level checks (ICMP, SNMP) |
| `description` | VARCHAR(255) | Yes | NULL | Optional notes |
| `created_at` | VARCHAR(32) | No | — | Record creation datetime |

**Migration 0011.** ✓ Implemented.

---

### `device_addresses`

One row per IP address on an interface. Supports both IPv4 and IPv6.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | INTEGER PK | No | — | Auto-increment |
| `interface_id` | INTEGER FK | No | — | → `device_interfaces.id` CASCADE DELETE |
| `address` | VARCHAR(45) | No | — | IP address (IPv4 or IPv6). VARCHAR(45) fits full IPv6 with CIDR. |
| `family` | VARCHAR(4) | No | — | `ipv4` or `ipv6` |
| `is_primary` | INTEGER | No | `0` | 1 = primary address for this interface; used for checks when no specific address is configured |
| `created_at` | VARCHAR(32) | No | — | Record creation datetime |

**Indexes:** `device_addresses_address (address)` — fast lookup during discovery matching.

**Migration 0012.** ✓ Implemented.

---

### `monitored_services`

Defines what to check on a device. Not the results — only the configuration of each check.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | INTEGER PK | No | — | Auto-increment |
| `device_id` | INTEGER FK | No | — | → `devices.id` CASCADE DELETE |
| `name` | VARCHAR(128) | No | — | Human label (e.g. "Ping", "HTTPS", "SSH") |
| `protocol` | VARCHAR(16) | No | — | `icmp`, `tcp`, `udp`, `http`, `https` |
| `port` | INTEGER | Yes | NULL | NULL for ICMP; required for TCP/UDP/HTTP checks |
| `check_address_id` | INTEGER FK | Yes | NULL | → `device_addresses.id` SET NULL. NULL = use device's primary management address |
| `check_interval_seconds` | INTEGER | No | `60` | How often to run this check |
| `is_active` | INTEGER | No | `1` | 0 = paused |
| `created_at` | VARCHAR(32) | No | — | Record creation datetime |

**Migration 0013.**

---

### `service_checks`

Append-only log of each individual check result. Drives status updates and alert evaluation.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | INTEGER PK | No | — | Auto-increment |
| `service_id` | INTEGER FK | No | — | → `monitored_services.id` CASCADE DELETE |
| `status` | VARCHAR(16) | No | — | `ok`, `fail`, `timeout` |
| `response_ms` | INTEGER | Yes | NULL | Round-trip time in milliseconds. NULL if unreachable. |
| `detail` | VARCHAR(255) | Yes | NULL | Optional detail (e.g. "Connection refused", "HTTP 503") |
| `checked_at` | VARCHAR(32) | No | — | When this check ran |

**Indexes:**
- `service_checks_service_id (service_id)` — fetch history per service
- `service_checks_checked_at (checked_at)` — time-range queries

**Note on volume:** This table grows with every monitoring cycle. A retention policy (e.g. keep last 1,000 rows per service, or purge rows older than 30 days) should be implemented once the monitoring runner exists. The table design supports either approach without schema changes.

**Migration 0014.**

---

### `alerts`

Stateful alert records. The core rule: **at most one open alert per (device_id, service_id, alert_type) tuple.** The monitoring runner enforces this before inserting.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | INTEGER PK | No | — | Auto-increment |
| `device_id` | INTEGER FK | No | — | → `devices.id` CASCADE DELETE |
| `service_id` | INTEGER FK | Yes | NULL | → `monitored_services.id` SET NULL. NULL = device-level alert (e.g. device unreachable) |
| `alert_type` | VARCHAR(64) | No | — | Short machine-readable label: `service_down`, `device_unreachable`, `high_latency` |
| `severity` | VARCHAR(16) | No | `critical` | `critical`, `warning`, `info` |
| `status` | VARCHAR(16) | No | `open` | `open` or `resolved` |
| `message` | TEXT | No | — | Human-readable description generated at alert creation |
| `occurrence_count` | INTEGER | No | `1` | Incremented each time the condition is re-confirmed while still open |
| `opened_at` | VARCHAR(32) | No | — | When the alert was first triggered |
| `last_seen_at` | VARCHAR(32) | No | — | When the failing condition was last observed (updated on re-confirmation) |
| `last_notified_at` | VARCHAR(32) | Yes | NULL | When the last notification was sent for this alert. Drives 15-min reminder throttle. |
| `resolved_at` | VARCHAR(32) | Yes | NULL | When the condition cleared. NULL = still open. |

**Indexes:**
- `alerts_open (device_id, service_id, alert_type, status)` — fast deduplication lookup
- `alerts_status (status)` — list all open alerts

**Migration 0015.**

---

### `notification_history`

Immutable log of every notification dispatched. One row per send attempt per alert.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | INTEGER PK | No | — | Auto-increment |
| `alert_id` | INTEGER FK | No | — | → `alerts.id` CASCADE DELETE |
| `channel` | VARCHAR(32) | No | — | `email`, `webhook`, `slack`, etc. |
| `recipient` | VARCHAR(255) | No | — | Email address, webhook URL, or channel identifier |
| `notification_type` | VARCHAR(32) | No | — | `open` (first trigger), `reminder`, `resolved` |
| `success` | INTEGER | No | — | 1 = sent successfully, 0 = failed |
| `detail` | VARCHAR(255) | Yes | NULL | Error message on failure, or response code on success |
| `sent_at` | VARCHAR(32) | No | — | When the send was attempted |

**Migration 0016.**

---

### `discovery_jobs`

One row per subnet scan operation. Tracks job lifecycle.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | INTEGER PK | No | — | Auto-increment |
| `subnet` | VARCHAR(45) | No | — | CIDR notation, e.g. `192.168.1.0/24` |
| `status` | VARCHAR(16) | No | `pending` | `pending`, `running`, `done`, `failed` |
| `created_by` | INTEGER FK | Yes | NULL | → `users.id` SET NULL. The user who initiated the scan. |
| `started_at` | VARCHAR(32) | Yes | NULL | When the scan process started |
| `finished_at` | VARCHAR(32) | Yes | NULL | When the scan completed or failed |
| `result_summary` | TEXT | Yes | NULL | Human-readable summary: found N hosts, N new, N matched |
| `created_at` | VARCHAR(32) | No | — | When the job was queued |

**Migration 0017.**

---

### `discovery_findings`

One row per IP address found during a discovery scan. Staged for matching or review.

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | INTEGER PK | No | — | Auto-increment |
| `job_id` | INTEGER FK | No | — | → `discovery_jobs.id` CASCADE DELETE |
| `address` | VARCHAR(45) | No | — | IP address of the discovered host |
| `hostname` | VARCHAR(255) | Yes | NULL | Reverse DNS result. NULL if rDNS fails. |
| `is_alive` | INTEGER | No | — | 1 = responded to ICMP ping during scan |
| `responded_at` | VARCHAR(32) | Yes | NULL | When the host responded. NULL if not alive. |
| `merged_device_id` | INTEGER FK | Yes | NULL | → `devices.id` SET NULL. Set when this finding is associated with an existing device (auto or manual). |
| `merge_status` | VARCHAR(16) | No | `pending` | `pending` (awaiting review), `auto_matched`, `manually_matched`, `ignored` |
| `created_at` | VARCHAR(32) | No | — | When this finding was recorded |

**Indexes:**
- `discovery_findings_address (address)` — cross-reference with `device_addresses.address`
- `discovery_findings_merge_status (merge_status)` — list unreviewed findings

**Migration 0018.**

---

## Alert Deduplication Design

The monitoring runner follows this logic on every check cycle for each service:

```
run_check(service) → result {ok, fail, timeout}

if result == ok:
    open_alert = find_open_alert(device_id, service_id, 'service_down')
    if open_alert:
        resolve(open_alert)          # set status='resolved', resolved_at=now
        send_notification(open_alert, type='resolved')
    update device.status (recalculate from all services)

if result == fail or timeout:
    open_alert = find_open_alert(device_id, service_id, 'service_down')
    if open_alert:
        # Alert already exists — do NOT insert a new one
        increment open_alert.occurrence_count
        update open_alert.last_seen_at = now
        check_reminder(open_alert)   # see below
    else:
        insert new alert(status='open', opened_at=now, last_seen_at=now, last_notified_at=now)
        send_notification(new_alert, type='open')
    update device.status
```

**`find_open_alert` query:**
```sql
SELECT * FROM alerts
WHERE device_id = ?
  AND service_id = ?
  AND alert_type = ?
  AND status = 'open'
LIMIT 1
```

Because `status` can only be `open` once per (device, service, type) — enforced by application logic, not a DB constraint — this query always returns 0 or 1 rows.

> A unique partial index on `(device_id, service_id, alert_type)` WHERE `status = 'open'` would enforce this at the DB level. SQLite supports partial indexes; MySQL does not (before 8.0). For now, application logic enforces it and the composite index makes the lookup fast.

---

## Notification Reminder Design (15-minute throttle)

The reminder check runs after confirming a check is still failing and an open alert already exists:

```
check_reminder(open_alert):
    if open_alert.last_notified_at IS NULL:
        send_notification(open_alert, type='reminder')
        update open_alert.last_notified_at = now
    else:
        seconds_since = now - open_alert.last_notified_at
        if seconds_since >= 900:   # 900 = 15 × 60
            send_notification(open_alert, type='reminder')
            update open_alert.last_notified_at = now
```

`last_notified_at` is stored on the alert row so:
- No extra table is required to track throttle state.
- The monitoring runner is stateless — it reads `last_notified_at` from the DB on every cycle.
- If the monitoring runner restarts, throttle state is preserved.

The 15-minute interval is a constant in the monitoring runner. In a future version it could become a per-device or per-service configurable value without schema changes (just add a column to `monitored_services`).

---

## Discovery and Device Identity / Merge Strategy

### The identity problem

A device may be discovered under multiple IPs (e.g. a router with two interfaces). No automated system can reliably decide these are the same physical box without human confirmation. The design stages decisions rather than asserting them.

### Auto-match

When a discovery scan finds an IP:

```
address = '192.168.1.1'
match = SELECT da.id, di.device_id
        FROM device_addresses da
        JOIN device_interfaces di ON da.interface_id = di.id
        WHERE da.address = ?
        LIMIT 1
```

- **Match found:** Set `findings.merged_device_id = match.device_id`, `merge_status = 'auto_matched'`. No new device created.
- **No match:** Leave `merged_device_id = NULL`, `merge_status = 'pending'`. Finding appears in the "Pending Review" list in the UI.

### Pending review

The UI will show a list of `discovery_findings WHERE merge_status = 'pending'`. For each, the admin can:
- **Create new device** from this finding
- **Attach to existing device** — sets `merged_device_id`, creates an interface+address entry
- **Ignore** — sets `merge_status = 'ignored'`

### Manual device merge (implemented)

Operators merge devices via `GET /devices/{id}/merge` → `POST /devices/{id}/merge`. No automatic merging is allowed.

The merge is executed by `DeviceRepository::mergeInto(int $sourceId, int $targetId)`:

1. Validate that source ≠ target and both are active (non-deleted) devices.
2. Transfer `device_interfaces`: `UPDATE device_interfaces SET device_id = target WHERE device_id = source`.
   `device_addresses` follow automatically (linked via `interface_id`, not `device_id`).
3. Transfer `monitored_services`: `UPDATE monitored_services SET device_id = target WHERE device_id = source`.
4. Transfer `alerts`: `UPDATE alerts SET device_id = target WHERE device_id = source`.
5. Retarget `discovery_findings`: `UPDATE discovery_findings SET matched_device_id = target WHERE matched_device_id = source`.
6. Soft-delete source: `UPDATE devices SET merged_into_device_id = target, deleted_at = now WHERE id = source`.

Nothing is hard-deleted. All historical check data (`device_checks`, `service_checks`) is preserved.

After the merge the browser redirects to the target device page (`/devices/{targetId}?merged=SourceName`)
where a dismissible success banner confirms the operation.

Merged devices are excluded from all normal queries by `WHERE deleted_at IS NULL`. Their historical records remain intact and are reachable via the canonical (target) device after the transfer.

**No chains.** `merged_into_device_id` is intentionally shallow (one level). Chains (A → B → C) are prevented because the UI only lists active (non-deleted) devices as merge targets, enforced by `DeviceRepository::findAllForSelect()` which filters `WHERE deleted_at IS NULL`.

---

## Migration Path: v1 → Full Model

The v1 `devices` table stays in place throughout. The expansion happens in additive steps.

### Step 1 — Extend `devices` (migration 0010) ✓

Add `merged_into_device_id` and `deleted_at` to `devices`. Safe `ALTER TABLE ADD COLUMN`. No data migration.

### Step 2 — Add interfaces and addresses (migrations 0011–0012) ✓

Create `device_interfaces` and `device_addresses`. No changes to `devices`.

### Step 3 — Migrate v1 host data (migration 0013) ✓

Data migration only — no DDL. For each active device with a non-empty `host`:
- Insert one `device_interfaces` row (`name = 'Primary'`, `is_management = 1`)
- Insert one `device_addresses` row (`address = devices.host`, `family` auto-detected, `is_primary = 1`)

`devices.host` is left in place. Both sources are valid during the transition. All existing devices now have interface/address records. See *Transitional State* note above.

### Step 4 — Refactor repository to query via device_addresses (no migration) ✓

`DeviceRepository::findAll()` now resolves `address` via a correlated subquery against `device_interfaces` (is_management=1) + `device_addresses` (is_primary=1), falling back to `devices.host`. Active-device filter added (`WHERE deleted_at IS NULL`). The view reads `$device['address']`.

### Step 4b — Device CRUD write path (no migration) ✓

`DeviceRepository::create()` and `update()` now write to `device_interfaces` and `device_addresses` in addition to `devices`. `devices.host` is kept in sync as a transitional fallback. `DeviceRepository::softDelete()` sets `deleted_at`.

### Step 4c — Device-level monitoring runner (migration 0014) ✓

`device_checks` table added. `scripts/monitor.php` performs ICMP checks against active devices, appends rows to `device_checks`, and updates `devices.status`. See [monitoring.md](monitoring.md) for details.

**Note on migration numbering:** The planned sequence had `monitored_services` at migration 0014. The actual implementation uses 0014 for `device_checks` (an intermediate step). When `monitored_services` is added, it will be migration 0015. The domain model's planned migration numbers from 0014 onwards should be treated as approximate.

### Step 5 — Add monitored_services (migration 0017) ✓

Fully additive. Defines TCP services to check per device. `monitoring_enabled`,
`expected_state`, `last_state`, and `last_check_at` columns are all present.

### Step 6 — Add service_checks (migration 0018) ✓

Fully additive. Append-only historical log of service check results. Indexed on
`(service_id, checked_at)` for efficient per-service time-range queries.

### Step 7 — Service-level check runner ✓ (code only, no migration)

`TcpChecker` added. `scripts/monitor.php` extended with a second service check
pass after device checks. `ServiceCheckRepository` handles target selection,
check persistence, and state updates.

### Step 8 — Add discovery tables (migrations 0019–0020)

Fully additive.

### Step 9 — Drop deprecated `devices.host` and `devices.last_check_at`

Once all code reads from `device_addresses` / `service_checks`, drop the deprecated columns via table rebuild. Deferred until the monitoring runner is complete and all consumers are migrated.

---

## Recommended Implementation Order

These are ordered by value delivered and dependency chain.

| Phase | Work | Migrations | Unlocks |
|-------|------|------------|---------|
| **1** ✓ | Extend `devices` with `merged_into_device_id` + `deleted_at` | 0010 | Soft delete, merge readiness |
| **2** ✓ | `device_interfaces` + `device_addresses` + data migration of `host` | 0011–0013 | Multi-IP support, discovery matching |
| **3** ✓ | Refactor `DeviceRepository` to query via `device_addresses` | — (code only) | UI sees real interface/address data |
| **4** ✓ | Device CRUD write path + soft-delete | — (code only) | Create/edit/delete devices from the browser |
| **5** ✓ | `device_checks` + device-level monitoring runner | 0014 | ICMP checks, status updates, check history |
| **6** ✓ | `alerts` + deduplication logic in monitoring runner | 0015 | Stateful device_offline alerts, no duplicate rows |
| **7** ✓ | `notification_history` + log/webhook dispatch in monitoring runner | 0016 | Notifications sent and logged with 15-min throttle |
| **8** ✓ | `monitored_services` + `service_checks` + TCP service check runner | 0017–0018 | Service-level monitoring foundation, device detail shows services |
| **9** ✓ | `service_down` alert type wired in runner service pass | — (code only) | Service failures create stateful alerts; notifications via existing channels |
| **10** ✓ | `discovery_jobs` + `discovery_findings` + scan runner + operator workflow + suggestion system | 0019–0020 | Subnet discovery, link/create/ignore actions, MAC/hostname suggestions |
| **11** | **Notes module** — `notes` table; `NoteRepository`, `NoteService`; device detail Notes section | 0021 | Operator annotations on devices (and any future entity) |
| **12** | **Device detail — Alerts section** — add `findByDevice()` to controller + view section | — (code only) | Device detail page shows open alerts in context |
| **13** | **Reusable Notifications module** — `module_notifications` + `module_notification_deliveries`; `InAppChannel`, `EmailChannel`; user inbox UI | 0022–0023 | In-app notification inbox, email delivery, badge count in nav |
| **14** | `monitored_services` CRUD UI | — (code only) | Services configurable from the browser |
| **15** | Drop deprecated `devices.host` + `devices.last_check_at` | — | Schema cleanup |

**Phase 3 (repository refactor) must come before Phase 5 (monitoring runner).** The runner needs to know which address to check. If `DeviceRepository` still reads `devices.host` instead of `device_addresses`, the runner cannot use the new multi-IP model.

---

## Full Schema Summary (Target State)

```
devices
  ├─< device_interfaces
  │     └─< device_addresses
  ├─< monitored_services
  │     └─< service_checks
  ├─< alerts
  │     └─< notification_history
  └── merged_into_device_id (→ devices, self-ref)

discovery_jobs
  └─< discovery_findings ──→ devices (optional)
```

| Table | Rows grow | Retention concern |
|-------|-----------|-------------------|
| `devices` | Slow (human-managed) | No |
| `device_interfaces` | Slow | No |
| `device_addresses` | Slow | No |
| `monitored_services` | Slow (human-managed) | No |
| `service_checks` | **Fast** (every check cycle) | Yes — purge or cap per service |
| `alerts` | Medium (opens and resolves) | No |
| `notification_history` | Medium | Archivable after N days |
| `discovery_jobs` | Slow (user-initiated) | No |
| `discovery_findings` | Medium (per subnet size) | Archivable after merge |
