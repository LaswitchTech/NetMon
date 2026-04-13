# NetMon Database Schema

> Related documentation: [database.md](database.md) (abstraction layer) · [migrations.md](migrations.md) (how schema changes are applied) · [architecture.md](architecture.md) (full system overview)

## Overview

The schema supports user authentication, group-based authorization, and API token access.
All tables use portable column types compatible with both SQLite (default) and MySQL/MariaDB (future).

---

## Entity Relationship (simplified)

```
users ──< user_groups >── groups ──< group_permissions >── permissions
  │
  └──< api_tokens
```

- A user belongs to zero or more groups (via `user_groups`).
- A group holds zero or more permissions (via `group_permissions`).
- A user's effective permissions are the union of all permissions from all their groups.
- A token inherits the user's permissions by default. Token-specific permission scoping is deferred (see below).

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

## Seeds

Seeds live in `/database/seeds/` and are run via `php scripts/seed.php`.
They are idempotent — safe to re-run.

| Seed | What it inserts |
|---|---|
| `AdminBootstrap` | `admin` group + 6 base permissions + all permissions granted to `admin` |

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
