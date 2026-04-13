# Installation Guide

## Overview

NetMon supports two installation methods:

| Method | When to use |
|---|---|
| CLI installer (`scripts/install.php`) | Servers, automation, CI pipelines, power users |
| Web wizard (`/setup`) | First-time users, hosted environments, guided setup |

Both methods execute the **same installation phases** through the same shared installer core. The difference is only in how input is collected and output is presented.

---

## System Requirements

| Requirement | Minimum | Recommended |
|---|---|---|
| PHP | 8.1 | 8.2+ |
| Web server | Apache + mod_rewrite, nginx | Apache 2.4+ |
| Disk space | 50 MB | 200 MB |

### Required PHP extensions

| Extension | Purpose |
|---|---|
| `pdo` | Database abstraction layer |
| `pdo_sqlite` | SQLite database driver (default) |
| `json` | API responses, config I/O |
| `openssl` | Token generation (`random_bytes`) |
| `mbstring` | String handling |

### Optional extensions

| Extension | Purpose |
|---|---|
| `pdo_mysql` | MySQL/MariaDB support (future) |
| `ldap` | LDAP authentication (future) |
| `imap` | IMAP authentication (future) |

### Writable directories

The installer requires write access to the following paths. They must exist or be creatable:

| Path | Purpose |
|---|---|
| `/storage/` | Logs, cache, install lock |
| `/data/` | SQLite database file |
| `/config/` | Generated `local.php` config |

---

## Installation Phases

Both the CLI installer and the web wizard execute the following phases in order. Each phase is atomic — failure at any step halts installation without partial state.

### Phase 1 — Environment Validation

**Purpose:** Confirm the server environment meets all prerequisites before touching any files or databases.

Checks performed:
- PHP version ≥ 8.1
- All required extensions are loaded: `pdo`, `pdo_sqlite`, `json`, `openssl`, `mbstring`
- Optional extensions detected and reported (not blocking)
- Web server rewrite rules reachable (web wizard only)

Outcome:
- All checks pass → proceed
- Any check fails → display actionable error, block progress

---

### Phase 2 — Directory Permission Check

**Purpose:** Ensure the installer can write configuration, databases, and lock files.

Checks performed:
- `/storage/` — exists and writable, or can be created
- `/data/` — exists and writable, or can be created
- `/config/` — exists and writable (for `local.php` generation)

Outcome:
- All paths OK → proceed
- Any path unwritable → display path and required permission (e.g., `chmod 775 storage/`), block progress

---

### Phase 3 — Database Driver Selection

**Purpose:** Choose the database backend and collect connection parameters.

#### SQLite (default)

- No external service required
- Database file path: `/data/app.db`
- No credentials required
- Proceed directly to Phase 4 (connection test)

#### MySQL / MariaDB (future)

Parameters collected:
- host (default: `127.0.0.1`)
- port (default: `3306`)
- database name
- username
- password
- charset (default: `utf8mb4`)

Validation:
- All required fields present
- Port is numeric and in range

---

### Phase 4 — Database Connection Test

**Purpose:** Confirm the database is reachable and usable before any writes occur.

#### SQLite

- Attempt to open (or create) the `.db` file via `PDO`
- Verify that WAL mode and foreign key pragmas can be applied
- Confirm the file is writable

#### MySQL (future)

- Attempt `new PDO("mysql:host=...;dbname=...;charset=utf8mb4", user, pass)`
- Verify the target database exists (or that the user has `CREATE DATABASE` privilege)
- Check PDO_MYSQL extension is loaded first

Outcome:
- Connection succeeds → proceed
- Connection fails → display driver error message (sanitized — no raw credentials in output), block progress

---

### Phase 5 — Application Configuration

**Purpose:** Collect and persist application-level settings that are stable per deployment.

Parameters collected:
- Application name (default: `NetMon`)
- Base URL (default: inferred from current request for web wizard, prompt for CLI)
- Environment: `development` or `production`
- Debug mode: on/off (default off in production)

Persisted to: `.env`

Generated `.env` structure:
```
APP_NAME=NetMon
APP_ENV=production
APP_DEBUG=false
APP_URL=https://example.com
APP_INSTALLED=false
```

`APP_INSTALLED` remains `false` until Phase 10 (finalization).

---

### Phase 6 — Local Configuration Persistence

**Purpose:** Write environment-specific, mutable configuration that may differ between deployments.

Parameters persisted:
- Database driver and connection details

Persisted to: `/config/local.php`

Generated `local.php` structure:
```php
<?php
return [
    'database' => [
        'driver' => 'sqlite',
        'sqlite' => [
            'path' => __DIR__ . '/../data/app.db',
        ],
    ],
];
```

For MySQL, the structure adds host/port/name/user/pass keys under `'mysql'`.

`local.php` is merged over `config/database.php` at boot time. Values in `local.php` take precedence.

---

### Phase 7 — Migration Execution

**Purpose:** Apply all pending database schema migrations.

Implementation:
- Uses existing `App\Core\MigrationRunner`
- Scans `/database/migrations/` for `{NNNN}_*.php` files
- Applies all pending migrations in numeric order
- Records each applied migration in the `migrations` table

Migrations applied during a fresh install (current set):
1. `0001_create_migrations_table`
2. `0002_create_users_table`
3. `0003_create_groups_table`
4. `0004_create_permissions_table`
5. `0005_create_user_groups_table`
6. `0006_create_group_permissions_table`
7. `0007_create_api_tokens_table`

Outcome:
- All migrations applied → proceed
- Any migration throws → rollback that migration, display error, halt

---

### Phase 8 — Seed / Bootstrap Execution

**Purpose:** Insert the minimum required reference data so the application is functional after install.

Seeds run:
1. `AdminBootstrap` — creates the `admin` group and inserts all core permissions:
   - `admin`, `users.view`, `users.create`, `users.edit`, `users.delete`, `api.access`
   - Grants all permissions to the `admin` group

Seeds are idempotent — safe to run more than once.

Note: NetMon-specific permission sets (monitoring, alerting, etc.) will be added as additional seeds when those features are built.

---

### Phase 9 — Administrator Account Creation

**Purpose:** Create the first user account and assign it full administrative access.

Parameters collected:
- Full name
- Username (must be unique, validated against `users` table)
- Email address (validated format, unique)
- Password (validated: minimum 8 characters; hashed with `password_hash` before storage)
- Password confirmation

Steps executed:
1. Hash password via `password_hash($password, PASSWORD_DEFAULT)`
2. Insert user row into `users` table (`is_active = 1`)
3. Resolve admin group ID from `groups` table
4. Insert pivot row into `user_groups` linking user → admin group

Outcome:
- Account created → proceed
- Duplicate username/email → display error, re-prompt

---

### Phase 10 — Finalization and Install Lock

**Purpose:** Mark installation as complete and prevent re-entry into the installer.

Steps executed:
1. Write `/storage/installed.lock` (empty file, existence is the flag)
2. Update `.env`: set `APP_INSTALLED=true`
3. Web wizard: display success message with link to `/auth/login`
4. CLI installer: print success and exit 0

Both lock signals must be present. To allow reinstallation, both must be removed manually.

**Boot guard:** `public/index.php` checks for the install lock during bootstrap. If not installed, it redirects all requests to the web wizard (or prints an error for CLI contexts). This check happens before the Container or Router are initialized, because the database may not yet exist.

---

## SQLite Setup (Default)

```
1. php scripts/install.php
   ↓
   Phase 1–2: env + directory check
   Phase 3: driver = sqlite (no input needed)
   Phase 4: PDO opens /data/app.db
   Phase 5: writes .env
   Phase 6: writes config/local.php (driver: sqlite)
   Phase 7: MigrationRunner::run()
   Phase 8: AdminBootstrap::run()
   Phase 9: creates admin user
   Phase 10: writes /storage/installed.lock, APP_INSTALLED=true
```

No external service configuration required. The SQLite file is created automatically.

---

## MySQL / MariaDB Setup (Future)

Same phases as SQLite, with these differences:

- Phase 3: prompts for host, port, database, username, password
- Phase 4: tests live MySQL PDO connection (requires `pdo_mysql`)
- Phase 6: writes MySQL block to `config/local.php`
- Phase 7: `MigrationRunner` uses MySQLDriver (not yet implemented)

The existing `DatabaseInterface` contract means migrations and seed files do not need changes — only the driver implementation changes.

---

## CLI Execution Flow

**Implemented.** Run:

```bash
php scripts/install.php
```

The script is fully interactive. It prompts for the values listed below, confirms before writing anything, then executes all installation phases.

### What the CLI prompts for

| Prompt | Notes |
|---|---|
| Application URL | Default: current `APP_URL` from `.env` |
| Database driver | SQLite selected from a numbered menu; MySQL shown as "not yet available" |
| Administrator full name | Display name stored in `display_name` column |
| Administrator username | 3–50 chars, alphanumeric + underscore |
| Administrator email | Validated format |
| Administrator password | Hidden input (stty -echo on Unix); minimum 8 chars |
| Password confirmation | Must match |

### What the CLI does NOT prompt for (predefined in `.env`)

| Setting | Where defined | Value |
|---|---|---|
| `APP_NAME` | `.env` | `NetMon` |
| `APP_ENV` | `.env` | `development` |
| `APP_DEBUG` | `.env` | `true` |
| SQLite file path | `config/database.php` default | `data/app.db` |

### Sample progress output

```
╔══════════════════════════════════════════════╗
║   NetMon — Installation Wizard              ║
╚══════════════════════════════════════════════╝

── Phase 1/8: Checking environment requirements
  [✓] PHP 8.1.0+ (8.2.0)
  [✓] ext-pdo
  [✓] ext-pdo_sqlite
  [~] ext-pdo_mysql (not loaded — MySQL / MariaDB support (future))

── Phase 2/8: Checking directory permissions
  [✓] /path/to/storage
  [✓] /path/to/data
  [✓] /path/to/config

── Phase 3/8: Gathering installation settings
  Application URL [http://localhost]: https://netmon.example.com
  ...

── Phase 4/8: Writing configuration
  [✓] Updated .env
  [✓] Wrote config/local.php

── Phase 5/8: Connecting to database
  [✓] Connection successful (Sqlite)

── Phase 6/8: Running database migrations
  [✓] Applied: 0001_create_migrations_table
  ...
  [✓] Applied: 0008_add_display_name_to_users

── Phase 7/8: Running database seeds
  [✓] Seeds complete.

── Phase 8/8: Creating administrator account and finalizing
  [✓] Administrator account created (ID: 1)
  [✓] Installation lock written
  [✓] APP_INSTALLED set to true

╔══════════════════════════════════════════════╗
║   Installation complete!                     ║
╚══════════════════════════════════════════════╝

  Application URL : https://netmon.example.com
  Login URL       : https://netmon.example.com/auth/login
  Admin username  : admin
```

### Exit codes

| Code | Meaning |
|---|---|
| `0` | Installation complete |
| `1` | Environment check failed |
| `2` | Directory permission error |
| `3` | Database connection failed |
| `4` | Migration failure |
| `5` | Seed failure |
| `6` | Admin account creation failed |
| `7` | Finalization failed |
| `99` | Already installed |

---

## Web Wizard Execution Flow

See [setup-wizard.md](setup-wizard.md) for full UX flow.

Summary:
```
GET /setup          → welcome screen (blocked post-install)
POST /setup/check   → Phase 1+2 (AJAX: returns JSON pass/fail)
POST /setup/db      → Phase 3+4 (AJAX: tests connection, returns result)
POST /setup/config  → Phase 5+6 (AJAX: validates and persists)
POST /setup/install → Phase 7+8+9+10 (executes install, streams progress)
GET /setup/done     → success screen
```

Wizard state (collected values) is stored in a PHP session keyed to the setup flow, cleared on completion or abandonment.

---

## Reinstallation / Reset

To allow reinstallation:

1. Delete `/storage/installed.lock`
2. Edit `.env`: set `APP_INSTALLED=false`
3. Optionally drop and recreate (or delete) the database

After removing both lock signals, the boot guard will redirect to the installer again.

> **Warning:** Reinstallation drops no data automatically. If you want a fresh database, delete `/data/app.db` (SQLite) or drop/recreate the MySQL database before running the installer.

---

## Deferred Decisions

| Decision | Notes |
|---|---|
| MySQLDriver implementation | Driver interface is ready; implementation not yet written |
| SMTP / email verification | Required before password reset; deferred |
| `token_permissions` table | Scoped token permission support; hook exists in `TokenService::verify()` |
| Multi-site / multi-tenant | Not planned; single install per deployment |
| Upgrade / update path | Not designed yet; migration system handles schema changes |
| Web installer CSRF protection | Must be added when implementing; one-time setup token pattern |
