# Devices Module

> **Schema note:** The `devices` table is in a transitional state. `device_interfaces` and `device_addresses` exist and are backfilled. Both the read and write paths now use the new tables. `devices.host` is still written on create/edit to keep the fallback safe but is deprecated. See [domain-model.md](domain-model.md) for the full evolution plan.

## Overview

The Devices module provides a list of monitored network devices. It is the first real data-backed module in NetMon.

Authentication is enforced by the `WebAuth` middleware — unauthenticated requests are redirected to `/auth/login`.

---

## Routes

| Method | Path | Middleware | Description |
|--------|------|------------|-------------|
| `GET` | `/devices` | `WebAuth` | Renders the Devices list page |
| `GET` | `/devices/create` | `WebAuth` | Renders the Add Device form |
| `POST` | `/devices` | `WebAuth` | Creates a new device |
| `GET` | `/devices/{id}` | `WebAuth` | Renders the read-only Device Detail page |
| `GET` | `/devices/{id}/edit` | `WebAuth` | Renders the Edit Device form |
| `POST` | `/devices/{id}` | `WebAuth` | Updates an existing device |
| `POST` | `/devices/{id}/delete` | `WebAuth` | Soft-deletes a device |
| `GET` | `/devices/{id}/merge` | `WebAuth` | Renders the Merge Device form |
| `POST` | `/devices/{id}/merge` | `WebAuth` | Executes the merge and redirects to the target device |

> **Route order:** `/devices/create` is registered before `/devices/{id}` so the literal segment `create` is not mistakenly captured as an id parameter.

---

## File Structure

```
database/
    migrations/
        0009_create_devices_table.php              ← Schema: devices table
        0010_add_merge_columns_to_devices.php      ← Phase 1: soft-delete + merge columns
        0011_create_device_interfaces_table.php    ← Phase 2: interfaces table
        0012_create_device_addresses_table.php     ← Phase 2: addresses table
        0013_migrate_device_host_to_addresses.php  ← Phase 2: data migration
    seeds/
        DeviceSeed.php                             ← Sample devices (dev only)

app/
    Models/
        DeviceRepository.php            ← All DB queries for devices (read + write)
        DeviceCheckRepository.php       ← Check history queries (read) + check persistence
    NetMon/Controllers/
        DeviceController.php            ← All device browser routes
    Views/devices/
        index.php                       ← Device list with Edit/Delete actions; names link to detail
        create.php                      ← Add Device form
        edit.php                        ← Edit Device form
        show.php                        ← Read-only Device Detail page
        merge.php                       ← Operator-driven Merge Device form
```

---

## Database Schema

### `devices` (transitional)

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | INTEGER PK | No | — | Auto-increment primary key |
| `name` | VARCHAR(128) | No | — | Human-readable device label |
| `host` | VARCHAR(255) | No | — | **Deprecated.** Single IP or hostname. Superseded by `device_addresses`. Retained until all code reads from the new tables. |
| `status` | VARCHAR(32) | No | `unknown` | Aggregate status: `online`, `offline`, `degraded`, `unknown` |
| `last_check_at` | VARCHAR(32) | Yes | NULL | **Transitional.** Written by the monitoring runner (`scripts/monitor.php`) after each check pass. Will be superseded by querying `device_checks.checked_at` directly once the UI reads history from the checks table. |
| `merged_into_device_id` | INTEGER FK | Yes | NULL | Self-reference. Non-null = soft-deleted/merged record. |
| `deleted_at` | VARCHAR(32) | Yes | NULL | Soft-delete timestamp. NULL = active. |
| `created_at` | VARCHAR(32) | No | — | Record creation datetime |

### `device_interfaces`

| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| `id` | INTEGER PK | No | — |
| `device_id` | INTEGER FK | No | → `devices.id` CASCADE DELETE |
| `name` | VARCHAR(64) | No | e.g. `eth0`, `Primary`, `WAN` |
| `mac_address` | VARCHAR(17) | Yes | NULL if unknown |
| `is_management` | INTEGER | No | 1 = preferred interface for monitoring checks |
| `description` | VARCHAR(255) | Yes | Optional notes |
| `created_at` | VARCHAR(32) | No | — |

### `device_addresses`

| Column | Type | Nullable | Notes |
|--------|------|----------|-------|
| `id` | INTEGER PK | No | — |
| `interface_id` | INTEGER FK | No | → `device_interfaces.id` CASCADE DELETE |
| `address` | VARCHAR(45) | No | IPv4 or IPv6 |
| `family` | VARCHAR(4) | No | `ipv4` or `ipv6` |
| `is_primary` | INTEGER | No | 1 = address to use by default for this interface |
| `created_at` | VARCHAR(32) | No | — |

---

## Data Flow

```
devices + device_interfaces + device_addresses
    ↓  JOIN query (see Address Resolution below)
DeviceRepository::findAll()
    ↓  $devices — each row includes resolved `address` key
DeviceController::index()
    ↓  passed into ob_start() scope
app/Views/devices/index.php
    ↓  renders $device['address'] in Host / IP column
app/Views/layouts/app.php
    ↓  HTTP 200 response
Browser
```

---

## DeviceRepository

**Class:** `App\Models\DeviceRepository`

| Method | Description |
|--------|-------------|
| `findAll(): array` | Return all active devices ordered by name, with resolved `address` |
| `findById(int $id): ?array` | Return a single active device by ID; null if not found or soft-deleted |
| `findAllForSelect(): array` | Return lightweight id/name list of all active devices (for dropdowns) |
| `findInterfacesWithAddresses(int $deviceId): array` | Return all interfaces with nested address arrays for one device |
| `possibleDuplicates(int $deviceId): array` | Return other active devices that share a strong identity signal (MAC or hostname); informational only |
| `create(array $data): int` | Create a device with a default management interface and primary address; return new device ID |
| `update(int $id, array $data): void` | Update a device's name and primary address (upserts interface/address records) |
| `softDelete(int $id): void` | Set `deleted_at`; device is excluded from all active queries thereafter |
| `mergeInto(int $sourceId, int $targetId): void` | Transfer all data from source to target, then soft-delete source with `merged_into_device_id` set |

Returns raw arrays — no domain objects.

### `findInterfacesWithAddresses(int $deviceId): array`

Used by the Device Detail page. Performs a LEFT JOIN across `device_interfaces` and `device_addresses`, then groups the flat result into a nested structure in PHP:

```php
[
    [
        'id'            => int,
        'name'          => string,       // e.g. 'Primary', 'eth0'
        'mac_address'   => string|null,
        'is_management' => int,          // 1 = management interface
        'description'   => string|null,
        'addresses'     => [
            [
                'id'         => int,
                'address'    => string,
                'family'     => string,  // 'ipv4' or 'ipv6'
                'is_primary' => int,
            ],
            // ...
        ],
    ],
    // ...
]
```

Management interfaces are listed first; primary addresses are listed first within each interface.

### Write-path behavior (Phase 4)

All write methods follow the same transitional pattern:

1. **`devices` row** — always written first. `devices.host` is kept in sync with the primary address for the duration of the transition.
2. **`device_interfaces` row** — one `name='Primary'`, `is_management=1` interface per device. Created on `create()`; found and updated on `update()`.
3. **`device_addresses` row** — one `is_primary=1` address per management interface. Created on `create()`; updated on `update()` (inserted if missing).

This means that after a `create()` or `update()` call:
- `devices.host` equals the submitted address
- The management interface primary address equals the submitted address
- `findAll()` and `findById()` resolve the same value from both sources

#### `create(array $data)`

```
INSERT devices (name, host=address, status='unknown', created_at)
INSERT device_interfaces (device_id, name='Primary', is_management=1, description, created_at)
INSERT device_addresses (interface_id, address, family, is_primary=1, created_at)
```

#### `update(int $id, array $data)`

```
UPDATE devices SET name=?, host=address WHERE id=?
SELECT device_interfaces WHERE device_id=? AND is_management=1 LIMIT 1
  → if found:
      UPDATE device_interfaces SET description=? WHERE id=?
      UPDATE device_addresses SET address=?, family=? WHERE interface_id=? AND is_primary=1
      (or INSERT if primary address row is missing)
  → if not found:
      INSERT device_interfaces ...
      INSERT device_addresses ...
```

#### `softDelete(int $id)`

```
UPDATE devices SET deleted_at=now WHERE id=? AND deleted_at IS NULL
```

Interface and address records are left intact. They are preserved for potential future merge workflows and historical lookups.

### Address resolution

`findAll()` resolves the display address for each device using a correlated subquery with this preference order:

1. **Primary address** (`is_primary = 1`) on the **management interface** (`is_management = 1`)
2. **Fallback:** `devices.host` — used when no matching interface/address record exists

```sql
SELECT
    d.id,
    d.name,
    d.host,
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
        d.host
    ) AS address,
    d.status,
    d.last_check_at,
    d.created_at
FROM   devices d
WHERE  d.deleted_at IS NULL
ORDER  BY d.name ASC
```

The correlated subquery guarantees exactly one row per device regardless of how many interfaces or addresses a device has. `LIMIT 1` resolves any data inconsistency (e.g. multiple management interfaces) deterministically.

### Active-device filter

`WHERE d.deleted_at IS NULL` excludes all soft-deleted and merged devices. A device with a non-null `deleted_at` is never returned to callers, even if it has valid interface/address data.

### Returned row shape

```php
[
    'id'            => int,
    'name'          => string,
    'host'          => string,   // deprecated — transitional, do not use in new code
    'address'       => string,   // resolved from device_addresses, or host fallback
    'status'        => string,
    'last_check_at' => string|null,
    'created_at'    => string,
]
```

---

## Controller

**Class:** `App\NetMon\Controllers\DeviceController`

| Method | Route | Description |
|--------|-------|-------------|
| `index()` | `GET /devices` | Fetch device list; render list view |
| `show()` | `GET /devices/{id}` | Load device, interfaces, recent checks; render detail view (read-only) |
| `createForm()` | `GET /devices/create` | Render empty Add Device form |
| `store()` | `POST /devices` | Validate, call `create()`, redirect to `/devices` |
| `editForm()` | `GET /devices/{id}/edit` | Load device, render pre-populated Edit form |
| `update()` | `POST /devices/{id}` | Validate, call `update()`, redirect to `/devices` |
| `delete()` | `POST /devices/{id}/delete` | Call `softDelete()`, redirect to `/devices` |
| `mergeForm()` | `GET /devices/{id}/merge` | Load source device and candidate list; render merge form |
| `merge()` | `POST /devices/{id}/merge` | Validate target, call `mergeInto()`, redirect to target device with `?merged=SourceName` |

All device controller methods set `$activeSection = 'Devices'` so the sidebar Devices link remains highlighted on create/edit sub-pages.

All methods follow the standard two-step render pattern:
```php
ob_start();
require $viewsPath . '/devices/<view>.php';
$content = ob_get_clean();

http_response_code(200);
header('Content-Type: text/html; charset=utf-8');
require $viewsPath . '/layouts/app.php';
```

See [dashboard.md](dashboard.md) for full documentation of the render pattern.

### Validation

`DeviceController::validateDevice(string $name, string $address): array` checks:
- Name: required, max 128 chars
- Address: required, must be a valid IPv4, IPv6, or hostname (single-label or FQDN)

Validation errors are passed as `$errors` into the form views for inline field-level display. The form re-renders at HTTP 422 with previously submitted values preserved.

---

## Seeding Sample Data

`database/seeds/DeviceSeed.php` inserts three sample devices for development:

| Name | Host | Status |
|------|------|--------|
| Core Router | 192.168.1.1 | online |
| Distribution Switch | 192.168.1.2 | online |
| File Server | 192.168.1.10 | offline |

The seed inserts into `devices` only. Migration 0013 automatically backfills `device_interfaces` and `device_addresses` for any device that has a `host` value and no existing interface record.

The seed is **not wired into the installer** — it is sample data for development and demo purposes only. Run it via `php scripts/seed.php DeviceSeed`.

---

## Transitional State: `devices.host` vs `device_addresses`

Both columns currently contain the same value for all devices. The system is in a dual-source state:

| Source | Written by | Read by | Status |
|--------|-----------|---------|--------|
| `devices.host` | `DeviceSeed`, `DeviceRepository::create/update` | Nothing — fallback only | Deprecated |
| `device_addresses.address` | Migration 0013, `DeviceRepository::create/update` | `DeviceRepository::findAll/findById` | Canonical |

**Current state (Phase 4):**
- New code reads `device['address']`, not `device['host']`
- `devices.host` is still written on create/update so the `COALESCE` fallback in read queries remains safe
- Both values are always in sync after a write

**`devices.host` can be dropped when:**
1. ~~All read paths use `device_addresses`~~ (done — Phase 3)
2. ~~All write paths create/update the corresponding `device_addresses` row~~ (done — Phase 4)
3. The monitoring runner uses `device_addresses` for check targets (Phase 5)
4. A schema migration removes the column from `devices`

---

## Duplicate-Device Suggestions

The Device Detail page (`GET /devices/{id}`) may display a **Possible Duplicate Devices** warning card when other active devices share a strong identity signal with the current device. These suggestions are **informational only** — no merge or action is taken automatically.

### How it works

`DeviceRepository::possibleDuplicates(int $deviceId): array` runs two read-only queries:

1. **MAC address match (strong signal)**
   Finds other active devices that share any `device_interfaces.mac_address` value with the current device. A shared MAC strongly suggests the same physical NIC, and therefore the same physical host.

2. **Hostname match via discovery (weaker signal)**
   Finds other active devices linked (via `matched_device_id`) to a discovery finding whose `hostname` matches any hostname linked to the current device. Hostname matches are heuristic — the same hostname can appear on different subnets or on re-assigned hosts.

Results are returned in priority order: MAC matches first, then hostname-only matches for devices not already present via a MAC match. The device itself is never included. Soft-deleted devices are excluded.

Each result row carries:
- `match_reason` — `'mac'` or `'hostname'`
- `match_value` — the shared MAC or hostname string

### UI behaviour

When `$possibleDuplicates` is non-empty, the view renders a left-yellow-bordered card above the Interfaces section. Each suggestion shows:

| Column | Content |
|--------|---------|
| Device | Linked name → `/devices/{id}` |
| Address | Management address of the candidate |
| Signal | Badge: **MAC match** (warning) or **Hostname match** (secondary) + "weaker signal" note |
| Matched Value | The shared MAC or hostname |
| Action | **Merge** button → `/devices/{current-id}/merge` |

The card is hidden entirely when there are no suggestions (`if (!empty($possibleDuplicates))`).

### Rules enforced

- No automatic merge, link, or mutation ever occurs from this feature.
- The operator must navigate to the merge form and explicitly confirm a merge.
- MAC matches are labelled as strong but the UI notes they are not infallible (NICs can be replaced).
- Hostname matches are labelled as weaker and heuristic.

---

## Merge Workflow

The merge feature lets operators consolidate two device records that represent the same physical host into a single canonical device. All merges are explicit and operator-driven — no automatic merging ever occurs.

### Entry point

Navigate to any device's detail page (`GET /devices/{id}`) and click the **Merge** button. This loads `GET /devices/{id}/merge`, which shows:

- A summary card of the **source device** (the one to be deactivated)
- A dropdown of all other active devices as potential **target** (canonical) devices

### Validation

Before executing, the controller checks:

| Check | Error |
|-------|-------|
| Target device selected | "Please select a target device." |
| Source ≠ target | "Cannot merge a device into itself." |
| Target is active (not deleted) | "Selected target device was not found or has been deleted." |

### Merge steps (`DeviceRepository::mergeInto`)

1. **Transfer interfaces** — `UPDATE device_interfaces SET device_id = target WHERE device_id = source`
   Device addresses follow automatically because they are linked via `interface_id` (not `device_id` directly).

2. **Transfer monitored services** — `UPDATE monitored_services SET device_id = target WHERE device_id = source`

3. **Transfer alerts** — `UPDATE alerts SET device_id = target WHERE device_id = source`
   Alert deduplication fingerprints (device + alert_type) now reference the target, preventing duplicates on the next monitoring cycle.

4. **Retarget discovery findings** — `UPDATE discovery_findings SET matched_device_id = target WHERE matched_device_id = source`
   Any pending or matched discovery findings that referenced the source now point to the target.

5. **Soft-delete source** — `UPDATE devices SET merged_into_device_id = target, deleted_at = now WHERE id = source`
   The source row is preserved for audit purposes. `deleted_at IS NOT NULL` removes it from all normal queries.

### What is NOT deleted

Nothing is hard-deleted during a merge:

| Data | Outcome |
|------|---------|
| `devices` source row | Soft-deleted (deleted_at set, merged_into_device_id set) |
| `device_interfaces` | Transferred to target (device_id updated) |
| `device_addresses` | Remain linked via interface_id — follow interfaces automatically |
| `monitored_services` | Transferred to target (device_id updated) |
| `service_checks` | Remain linked via service_id — follow services automatically |
| `device_checks` | Remain linked to their original device_id (source). Historical checks for the source are preserved but no longer reachable via the active device UI. |
| `alerts` | Transferred to target (device_id updated) |
| `discovery_findings` | Retargeted to target (matched_device_id updated) |

### Post-merge redirect

After a successful merge the browser is redirected to `GET /devices/{targetId}?merged=SourceName`. The target device detail page detects the `merged` query parameter and renders a dismissible success banner:

> Device **{SourceName}** was merged into this device. All interfaces, services, and alerts have been transferred.

### Safety rules

- **No automatic merging.** The `mergeInto()` method is only called from `DeviceController::merge()` after explicit operator confirmation.
- **No chains.** You can only merge into an active (non-deleted, non-merged) device. Chains (A → B → C) are prevented because `findById()` excludes soft-deleted devices from the target dropdown.
- **No MAC-based auto-match.** Merge is driven entirely by the operator picking a target. MAC-based suggestions may be added as a future UI hint, but they will never trigger an automatic merge.

### How this enables multi-IP devices

After a merge, a single target device holds interfaces and addresses from both the original source and target. This is the intended mechanism for consolidating a device that was initially discovered under two different IPs — e.g., a router whose management IP and a data-plane IP were separately monitored. Post-merge, both interfaces appear in the device detail page, and monitoring operates against both.

### Future improvements

| Item | Notes |
|------|-------|
| ~~MAC-based merge suggestion~~ | Done — `possibleDuplicates()` surfaces MAC and hostname signals on the device detail page |
| Merge audit log | Record who merged what and when in a dedicated audit table |
| Merge conflict detection | Warn if both devices have an open alert of the same type before merging |
| Undo / split | Allow an operator to split a merged device back out (requires separate implementation) |
| Merge from discovery | Shortcut on the discovery finding detail page to merge into an existing device |

---

## Device Detail Page Structure

The device detail page (`GET /devices/{id}`) is the primary operational view for a device.

### Layout

The page uses a **two-column layout** with a persistent left sidebar and a tabbed right workspace.

```
[Merge success banner — conditional, full width]
[Breadcrumb — full width]
[Device name + Edit / Merge buttons — full width]

┌──────────────────────┬──────────────────────────────────────────────┐
│  LEFT COLUMN         │  RIGHT COLUMN (tabbed)                       │
│  col-md-4 col-lg-3   │  col-md-8 col-lg-9                           │
│                      │                                              │
│  Overview card       │  [Summary] [Network] [Notes]  ← tab nav     │
│  Current Status card │  ─────────────────────────────────────────  │
│  Duplicates card     │  Summary tab (default):                      │
│  (conditional)       │    Open Alerts card                          │
│                      │    Service History graphs (conditional)      │
│                      │    Device Monitoring History (conditional)   │
│                      │    Recent Check History                      │
│                      │  Network tab:                                │
│                      │    Interfaces & Addresses (DataTables)       │
│                      │    Monitored Services (DataTables)           │
│                      │  Notes tab:                                  │
│                      │    Notes section (shared partial)            │
└──────────────────────┴──────────────────────────────────────────────┘
```

Columns collapse to full-width stacked on screens below `md` (768px).

---

### Always-visible elements (above the two-column layout)

| Element | Notes |
|---------|-------|
| Merge success banner | Conditional — shown after a `?merged=Name` redirect |
| Breadcrumb | Devices → device name |
| Device name heading | `h1.h4` with Edit and Merge action buttons |

The left column is also always visible (not inside tabs): Overview, Status, and Duplicate Suggestions are immediately scannable without clicking a tab.

---

### Left column contents

| Section | Source | Notes |
|---------|--------|-------|
| Overview card | `$device` | Address, description, added date |
| Current Status card | `$device` | Status badge, last check timestamp |
| Possible Duplicates card | `$possibleDuplicates` | Conditional — compact inline cards, warning accent; each shows device name, match signal (MAC/Hostname badge), matched value, Merge button |

---

### Tab: Summary (default)

The default tab shows operational state. It is visible immediately without clicking.

| Section | Source | Notes |
|---------|--------|-------|
| Open Alerts | `$openAlerts` | Conditional — red-accented DataTable when alerts exist; "no alerts" strip otherwise |
| Service History graphs | `$serviceHistories` | Conditional — latency line + status strip per service |
| Device Monitoring History | `$historySeries` | Conditional — latency line + status timeline |
| Recent Check History | `$recentChecks` | Last 50 checks; full DataTables with `buttons:null` (read-only) |

The tab button displays a `bg-danger` badge with the open alert count when alerts exist.

**Open Alerts DataTable:**
- `buttons:null` + dom without `B` — alerts are generated by the monitoring runner, not created manually
- Sorted by Last Seen descending
- Each row links to `/alerts/{id}` for detail and operator actions

**Recent Checks DataTable:**
- `buttons:null` + dom without `B` — read-only historical log
- Sorted by Checked At descending

---

### Tab: Network

Structural configuration data. Add/edit/delete actions live here.

| Section | Source | Notes |
|---------|--------|-------|
| Error flash | `$_GET['error']` | Conditional — shown when interface/address/service delete fails |
| Interfaces & Addresses | `$interfaces` | Full DataTables; "Add Interface" injected into buttons area via `dt.buttons().container()` |
| Monitored Services | `$services` | Full DataTables; "Add Service" injected into buttons area via `dt.buttons().container()` |

**DataTables column adjustment:** `tbl-interfaces` and `tbl-services` initialize while the tab is hidden (`display:none`). A `shown.bs.tab` listener on the Network tab button calls `dt.columns.adjust()` to correct column widths when the tab first becomes visible.

**Error auto-switch:** When `$_GET['error']` is set, a JS snippet auto-activates the Network tab so the error flash is immediately visible without requiring the user to click the tab.

All delete modals (interface, address, service) are rendered inside this tab pane. Bootstrap 5 modals use `position:fixed` and work correctly regardless of tab state.

---

### Tab: Notes

| Section | Source | Notes |
|---------|--------|-------|
| Notes section | `$notes` (from controller) | Shared `partials/notes-section.php`; includes note list and add-note form |

**Hash routing:** `DeviceController` redirects to `/devices/{id}#notes` after note add/delete operations. The page JS maps `#notes` → `#tab-notes` at load time so the Notes tab auto-activates on return from a note action.

See [notes-module.md](notes-module.md) for full module documentation.

---

### Tab persistence (URL hash)

The JS maintains URL hash state as tabs are switched:

| Tab | Hash in URL |
|-----|-------------|
| Summary | `#tab-summary` |
| Network | `#tab-network` |
| Notes | `#notes` (alias — matches controller redirect) |

On page load the JS checks the hash and activates the corresponding tab. The `#notes` alias is mapped to the Notes tab pane (`#tab-notes`) so controller redirects and direct navigation both work.

---

### Open Alerts section — implementation notes

**Controller:** `DeviceController::show()` loads all device alerts via `AlertRepository::findByDevice($id)` and filters to `status = 'open'` in PHP:

```php
$alertRepo  = new AlertRepository($this->container->get('db'));
$openAlerts = array_values(array_filter(
    $alertRepo->findByDevice($id),
    fn($a) => $a['status'] === 'open'
));
```

**View behaviour:**
- Always rendered in the Summary tab (not just when non-empty)
- Alert type rendered via `alertTypeLabel()` helper (`device_offline` → "Device Offline")
- Does not replace the `/alerts` page — contextual summary only

---

## What Is Still Missing (post-Phase 4)

| Feature | Notes |
|---------|-------|
| ~~Add device~~ | Done — Phase 4 |
| ~~Edit device~~ | Done — Phase 4 |
| ~~Delete device (soft)~~ | Done — Phase 4 |
| ~~Input validation (IP/hostname format)~~ | Done — Phase 4 |
| ~~Monitoring integration~~ | Done — Phase 5: `scripts/monitor.php` reads `device_addresses` for check targets |
| ~~Device detail page~~ | Done — Phase 6: `GET /devices/{id}` shows overview, interfaces, recent check history |
| ~~Check history graphs~~ | Done — Chart.js latency + status strip charts |
| ~~Service-level monitoring~~ | Done — Phase 8: monitored_services + service_checks |
| ~~Duplicate suggestions~~ | Done — `possibleDuplicates()` with MAC + hostname signals |
| ~~Device detail — Alerts section~~ | Done — `$openAlerts` via `AlertRepository::findByDevice()`, full DataTables, red accent card |
| ~~Device detail — full DataTables on Interfaces, Services, Open Alerts~~ | Done — all three use `NetMon.dt.init`; Add Interface and Add Service injected via `dt.buttons().container()` |
| ~~Device detail — two-column layout with tabbed workspace~~ | Done — left sidebar (overview, status, duplicates) + tabbed right column (Summary \| Network \| Notes) |
| ~~Device detail — Notes section~~ | Done — Notes tab in the tabbed workspace via `partials/notes-section.php` |
| `devices.host` removal | Blocked until monitoring runner no longer references `devices.host` directly |
| Name uniqueness check | Not yet enforced at the DB or application layer |
| Pagination | Needed as device count grows |
| Search / filter | Filter by status, search by name or address |
| Permissions | `devices.view`, `devices.create`, `devices.edit`, `devices.delete` not yet defined |
| Multi-interface editing | UI intentionally deferred; repository structure already supports it |
| ~~Hard-delete / merge~~ | Done — Phase 7: operator-driven merge via `DeviceRepository::mergeInto()` and `/devices/{id}/merge` UI |
