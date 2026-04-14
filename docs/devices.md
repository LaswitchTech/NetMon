# Devices Module

> **Schema note:** The `devices` table is in a transitional state. `device_interfaces` and `device_addresses` exist and are backfilled. The read path now resolves addresses from the new tables. `devices.host` is still present but deprecated. See [domain-model.md](domain-model.md) for the full evolution plan.

## Overview

The Devices module provides a list of monitored network devices. It is the first real data-backed module in NetMon.

Authentication is enforced by the `WebAuth` middleware — unauthenticated requests are redirected to `/auth/login`.

---

## Routes

| Method | Path | Middleware | Description |
|--------|------|------------|-------------|
| `GET` | `/devices` | `WebAuth` | Renders the Devices list page |

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
        DeviceRepository.php            ← All DB queries for devices
    NetMon/Controllers/
        DeviceController.php            ← GET /devices handler
    Views/devices/
        index.php                       ← Content fragment (rendered into the shell)
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
| `last_check_at` | VARCHAR(32) | Yes | NULL | **Deprecated.** Will be superseded by `service_checks.checked_at` once monitoring runs. |
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

Returns raw arrays — no domain objects.

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

`DeviceController::index()` follows the standard two-step render pattern:

```php
$deviceRepo = new DeviceRepository($this->container->get('db'));
$devices    = $deviceRepo->findAll();

ob_start();
require $viewsPath . '/devices/index.php';
$content = ob_get_clean();

http_response_code(200);
header('Content-Type: text/html; charset=utf-8');
require $viewsPath . '/layouts/app.php';
```

See [dashboard.md](dashboard.md) for full documentation of the render pattern.

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
| `devices.host` | `DeviceSeed`, future CRUD | Nothing (as of Phase 3) | Deprecated |
| `device_addresses.address` | Migration 0013, future CRUD | `DeviceRepository::findAll()` | Canonical |

**During this period:**
- New code must read `device['address']`, not `device['host']`
- `devices.host` must still be written on device create/edit so the fallback remains valid
- Both values should be kept in sync until `devices.host` is removed

**`devices.host` can be dropped when:**
1. All read paths use `device_addresses` (done as of Phase 3)
2. All write paths (create, edit) also create/update the corresponding `device_addresses` row
3. The monitoring runner uses `device_addresses` for check targets (Phase 5)
4. A schema migration rebuilds the `devices` table without the column

---

## What Is Still Missing Before Full CRUD

| Feature | Notes |
|---------|-------|
| Add device | `POST /devices` — must write to both `devices` and `device_interfaces`/`device_addresses` |
| Edit device | `GET /devices/{id}/edit`, `PUT /devices/{id}` — must update address records |
| Delete device | Soft-delete via `deleted_at`; eventually hard-delete or merge |
| Input validation | Name uniqueness, IP/hostname format check |
| Monitoring integration | Background runner reads `device_addresses` to know what to ping |
| Pagination | Needed as device count grows |
| Search / filter | Filter by status, search by name or address |
| Permissions | `devices.view`, `devices.create`, `devices.edit`, `devices.delete` not yet defined |
