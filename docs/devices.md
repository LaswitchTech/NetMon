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
| `findInterfacesWithAddresses(int $deviceId): array` | Return all interfaces with nested address arrays for one device |
| `create(array $data): int` | Create a device with a default management interface and primary address; return new device ID |
| `update(int $id, array $data): void` | Update a device's name and primary address (upserts interface/address records) |
| `softDelete(int $id): void` | Set `deleted_at`; device is excluded from all active queries thereafter |

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

## What Is Still Missing (post-Phase 4)

| Feature | Notes |
|---------|-------|
| ~~Add device~~ | Done — Phase 4 |
| ~~Edit device~~ | Done — Phase 4 |
| ~~Delete device (soft)~~ | Done — Phase 4 |
| ~~Input validation (IP/hostname format)~~ | Done — Phase 4 |
| ~~Monitoring integration~~ | Done — Phase 5: `scripts/monitor.php` reads `device_addresses` for check targets |
| ~~Device detail page~~ | Done — Phase 6: `GET /devices/{id}` shows overview, interfaces, recent check history |
| `devices.host` removal | Blocked until UI reads status/latency from `device_checks` rather than `devices.status` (Phase 6+) |
| Check history graphs | Requires charting library integration; data is already available in `device_checks` |
| Service-level monitoring | Requires `monitored_services` + `service_checks` tables; see monitoring.md |
| Name uniqueness check | Not yet enforced at the DB or application layer |
| Pagination | Needed as device count grows |
| Search / filter | Filter by status, search by name or address |
| Permissions | `devices.view`, `devices.create`, `devices.edit`, `devices.delete` not yet defined |
| Multi-interface editing | UI intentionally deferred; repository structure already supports it |
| Hard-delete / merge | Out of scope until merge workflows are planned |
