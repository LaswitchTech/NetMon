# NetMon Database Schema

> Related documentation: [database.md](database.md) (abstraction layer) · [migrations.md](migrations.md) (how schema changes are applied) · [architecture.md](architecture.md) (full system overview) · [domain-model.md](domain-model.md) (full monitoring schema roadmap)

## Overview

The schema supports user authentication, group-based authorization, and API token access.
All tables use portable column types compatible with both SQLite (default) and MySQL/MariaDB (future).

---

## Entity Relationship (current implemented tables)

```
users ──< user_groups >── groups ──< group_permissions >── permissions
  │
  └──< api_tokens

devices ──< device_interfaces ──< device_addresses
```

- A user belongs to zero or more groups (via `user_groups`).
- A group holds zero or more permissions (via `group_permissions`).
- A user's effective permissions are the union of all permissions from all their groups.
- A token inherits the user's permissions by default. Token-specific permission scoping is deferred (see below).
- The `devices` table anchors the monitoring schema. `device_interfaces` and `device_addresses` are now implemented. The remaining monitoring schema (services, alerts, discovery) is documented in [domain-model.md](domain-model.md).
- During the current transition, `devices.host` and `device_addresses.address` hold the same value. Application code still reads `devices.host` directly; a future repository refactor will switch to querying `device_addresses`.

---

## Tables

### `migrations`
Tracks applied migrations. Managed by `MigrationRunner`.

| Column | Type | Notes |
|---|---|---|
| id | INTEGER PK | Auto-assigned |
| name | VARCHAR(255) | Migration filename without .php |
| batch | INTEGER | Increments per run invocation |
| applied_at | VARCHAR(32) | ISO datetime string |

---

### `users`
Core identity record.

| Column | Type | Notes |
|---|---|---|
| id | INTEGER PK | Auto-assigned |
| username | VARCHAR(64) | Unique |
| email | VARCHAR(255) | Unique |
| password_hash | VARCHAR(255) | `password_hash()` output — never store raw |
| is_active | INTEGER | 1 = active, 0 = disabled. Default 1 |
| created_at | VARCHAR(32) | ISO datetime |
| updated_at | VARCHAR(32) | ISO datetime |

**Indexes:** `users_username_unique`, `users_email_unique`

---

### `groups`
Named collections of permissions assigned to users.

| Column | Type | Notes |
|---|---|---|
| id | INTEGER PK | Auto-assigned |
| name | VARCHAR(64) | Unique (e.g. `admin`, `viewer`) |
| description | TEXT | Optional human-readable label |
| created_at | VARCHAR(32) | ISO datetime |
| updated_at | VARCHAR(32) | ISO datetime |

**Indexes:** `groups_name_unique`

---

### `permissions`
Atomic capability tokens. Code-defined; no timestamps.

| Column | Type | Notes |
|---|---|---|
| id | INTEGER PK | Auto-assigned |
| name | VARCHAR(128) | Unique. Convention: `resource.action` or bare word |
| description | TEXT | Human-readable description |

**Indexes:** `permissions_name_unique`

**Seeded permissions:**

| Name | Purpose |
|---|---|
| `admin` | Full administrative access |
| `users.view` | View user list and profiles |
| `users.create` | Create new users |
| `users.edit` | Edit existing users |
| `users.delete` | Delete users |
| `api.access` | Use the API with a token |

---

### `user_groups`
Pivot: which users belong to which groups.

| Column | Type | Notes |
|---|---|---|
| id | INTEGER PK | Auto-assigned |
| user_id | INTEGER FK | → `users.id` CASCADE DELETE |
| group_id | INTEGER FK | → `groups.id` CASCADE DELETE |

**Indexes:** `user_groups_unique (user_id, group_id)`

---

### `group_permissions`
Pivot: which permissions a group grants.

| Column | Type | Notes |
|---|---|---|
| id | INTEGER PK | Auto-assigned |
| group_id | INTEGER FK | → `groups.id` CASCADE DELETE |
| permission_id | INTEGER FK | → `permissions.id` CASCADE DELETE |

**Indexes:** `group_permissions_unique (group_id, permission_id)`

---

### `api_tokens`
Personal access tokens for API authentication.

| Column | Type | Notes |
|---|---|---|
| id | INTEGER PK | Auto-assigned |
| user_id | INTEGER FK | → `users.id` CASCADE DELETE |
| name | VARCHAR(128) | Human-readable label (e.g. "CI deploy key") |
| token_hash | VARCHAR(255) | Unique. `hash('sha256', $rawToken)` — raw token shown once, never stored |
| last_used_at | VARCHAR(32) | NULL until first use |
| expires_at | VARCHAR(32) | NULL = never expires |
| revoked_at | VARCHAR(32) | NULL = active; non-NULL = revoked (stores when) |
| created_at | VARCHAR(32) | ISO datetime |

**Indexes:** `api_tokens_hash_unique`, `api_tokens_user_id`

**Token lifecycle:**
- Active: `revoked_at IS NULL AND (expires_at IS NULL OR expires_at > NOW)`
- Revoked: `revoked_at IS NOT NULL`
- Expired: `expires_at IS NOT NULL AND expires_at <= NOW`

---

### `devices` (v1 — transitional)

Logical identity anchor for a monitored network host. The current schema is a first slice; the full domain model is documented in [domain-model.md](domain-model.md).

| Column | Type | Notes |
|---|---|---|
| id | INTEGER PK | Auto-assigned |
| name | VARCHAR(128) | Human-readable label |
| host | VARCHAR(255) | **Transitional.** Single IP or hostname. Will be superseded by `device_addresses` in a future migration. |
| status | VARCHAR(32) | Cached aggregate status: `online`, `offline`, `degraded`, `unknown`. Default `unknown`. |
| last_check_at | VARCHAR(32) | **Transitional.** NULL until a check runs. Will be superseded by `service_checks.checked_at`. |
| created_at | VARCHAR(32) | ISO datetime |
| merged_into_device_id | INTEGER FK | Self-reference → `devices.id` ON DELETE SET NULL. Non-null = this record has been merged into another device and is soft-deleted. Added by migration 0010. |
| deleted_at | VARCHAR(32) | Soft-delete timestamp. NULL = active record. Set when a device is merged. Added by migration 0010. |

**Indexes:** `devices_status`, `devices_deleted_at`

**Active-device query convention:** all queries listing devices for normal use must filter `WHERE deleted_at IS NULL` to exclude merged/deleted records. See [domain-model.md](domain-model.md).

---

### `device_interfaces`

One named network interface per device. Groups addresses and carries physical-layer metadata.

| Column | Type | Notes |
|---|---|---|
| id | INTEGER PK | Auto-assigned |
| device_id | INTEGER FK | → `devices.id` CASCADE DELETE |
| name | VARCHAR(64) | Interface label (e.g. `eth0`, `WAN`, `Primary`) |
| mac_address | VARCHAR(17) | Optional. `AA:BB:CC:DD:EE:FF` format. NULL if unknown. |
| is_management | INTEGER | 1 = preferred interface for device-level checks. Default 0. |
| description | VARCHAR(255) | Optional notes |
| created_at | VARCHAR(32) | ISO datetime |

**Indexes:** `device_interfaces_device_id`, `device_interfaces_management (device_id, is_management)`

**Transitional note:** existing devices have one `Primary` interface each, created from `devices.host` by migration 0013.

---

### `device_addresses`

One IP address per row. A single interface may have multiple addresses (dual-stack, load-balanced, etc.).

| Column | Type | Notes |
|---|---|---|
| id | INTEGER PK | Auto-assigned |
| interface_id | INTEGER FK | → `device_interfaces.id` CASCADE DELETE |
| address | VARCHAR(45) | IPv4 or IPv6 address. VARCHAR(45) fits full IPv6 with prefix notation. |
| family | VARCHAR(4) | `ipv4` or `ipv6` |
| is_primary | INTEGER | 1 = primary address for checks when no specific address is configured. Default 0. |
| created_at | VARCHAR(32) | ISO datetime |

**Indexes:** `device_addresses_interface_id`, `device_addresses_address`

The `device_addresses_address` index supports discovery auto-matching: given a scanned IP, look up the owning device in one query.

**Transitional note:** all addresses currently mirror `devices.host`. The `devices.host` column is deprecated but not yet dropped — it will be removed once all code reads from `device_addresses`.

---

## Seeds

Seeds live in `/database/seeds/` and are run via `php scripts/seed.php`.
They are idempotent — safe to re-run.

| Seed | What it inserts |
|---|---|
| `AdminBootstrap` | `admin` group + 6 base permissions + all permissions granted to `admin` |
| `DeviceSeed` | 3 sample devices (Core Router, Distribution Switch, File Server). Dev/demo only — not run by the installer. |

---

## Migration Portability Notes

| Issue | Status |
|---|---|
| `INTEGER PRIMARY KEY` | SQLite treats this as `rowid` (auto-increment). MySQL requires explicit `AUTO_INCREMENT`. When `MySQLDriver` is added, migration files targeting MySQL should use `INTEGER NOT NULL AUTO_INCREMENT`. |
| `CREATE TABLE IF NOT EXISTS` | Supported on both engines. |
| `CREATE UNIQUE INDEX IF NOT EXISTS` | Supported on both engines. |
| `FOREIGN KEY … ON DELETE CASCADE` | Supported on both. SQLite requires `PRAGMA foreign_keys=ON` (set in `SQLiteDriver`). |
| Datetime storage | `VARCHAR(32)` storing ISO strings (`Y-m-d H:i:s`). Portable and readable. For MySQL, can migrate to `DATETIME` columns later without logic changes. |
| `TINYINT(1)` booleans | Not used. `INTEGER DEFAULT 0/1` used instead — portable. |

---

## Deferred

| Item | Reason |
|---|---|
| `token_permissions` table | Allows scoping a token to a subset of the user's permissions. `api_tokens.id` is the FK target. See [auth.md — Deferred](auth.md#deferred-improvements). |
| `MySQLDriver` auto-increment | Will require a one-line DDL change per migration when MySQL support is added. See [migrations.md — Portability Notes](migrations.md#portability-notes). |
| LDAP / IMAP authentication | Out of scope per project rules. |
