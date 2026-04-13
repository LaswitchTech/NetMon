# NetMon Migration System

## Overview

Schema changes are managed through versioned migration files. The `MigrationRunner` tracks which migrations have been applied in a `migrations` database table and runs pending ones in filename order.

Seeds (initial data inserts) are separate from migrations and documented at the bottom of this file.

---

## CLI Commands

All commands are run from the project root:

```bash
# Apply all pending migrations
php scripts/migrate.php

# Roll back the last batch
php scripts/migrate.php rollback

# Show applied and pending status
php scripts/migrate.php status
```

---

## Migration File Format

**Location:** `database/migrations/`  
**Naming:** `{NNNN}_{description}.php`

- `NNNN` — zero-padded integer (e.g. `0001`, `0002`). Determines run order.
- `description` — snake_case description. Becomes the PHP class name via StudlyCase conversion.

**Class name derivation:**  
`0002_create_users_table` → strip `0002_` → `create_users_table` → `CreateUsersTable`

**Template:**

```php
<?php

use App\Core\Migration;

class CreateExampleTable extends Migration
{
    public function up(): void
    {
        $this->db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS example (
                id         INTEGER      NOT NULL,
                name       VARCHAR(128) NOT NULL,
                created_at VARCHAR(32)  NOT NULL,
                PRIMARY KEY (id)
            )'
        );
    }

    public function down(): void
    {
        $this->db->pdo()->exec('DROP TABLE IF EXISTS example');
    }
}
```

- `$this->db` is a `DatabaseInterface` instance injected by `Migration` base class.
- Use `$this->db->pdo()->exec()` for DDL. Use `$this->db->execute()` for DML.
- Migration files are included via `require_once` — they do **not** use the autoloader and must not declare a namespace.

---

## Current Migrations

| # | File | Creates |
|---|---|---|
| 0001 | `create_migrations_table` | `migrations` tracking table |
| 0002 | `create_users_table` | `users` |
| 0003 | `create_groups_table` | `groups` |
| 0004 | `create_permissions_table` | `permissions` |
| 0005 | `create_user_groups_table` | `user_groups` (pivot) |
| 0006 | `create_group_permissions_table` | `group_permissions` (pivot) |
| 0007 | `create_api_tokens_table` | `api_tokens` |

For column definitions, see → [schema.md](schema.md).

---

## Batch Tracking

Each `php scripts/migrate.php run` invocation assigns the same **batch number** to all migrations applied in that run. Rolling back reverts the most recent batch as a group.

The `migrations` table records:

| Column | Purpose |
|---|---|
| `id` | Auto-assigned row ID |
| `name` | Migration filename without `.php` |
| `batch` | Which run applied this migration |
| `applied_at` | Timestamp of application |

---

## Rollback Semantics

`rollback` reverses the **last batch only** (the most recent run). Each migration's `down()` method is called in reverse order within the batch.

**Important:** the migration record is deleted from the `migrations` table **before** `down()` runs. This prevents a chicken-and-egg failure when rolling back migration `0001` (which drops the `migrations` table itself).

After rollback, the migrations are back in `pending` state and can be re-applied with `run`.

---

## Runner Internals

**File:** `app/Core/MigrationRunner.php`

`ensureTable()` creates the `migrations` tracking table using `CREATE TABLE IF NOT EXISTS` before every operation. This bootstraps the system on a fresh database without needing migration `0001` to have already run.

Migration `0001` exists as a formal schema record and provides a clean `down()` path for `DROP TABLE IF EXISTS migrations`. It is not the only thing that creates the table.

---

## Portability Notes

See → [schema.md — Migration Portability Notes](schema.md#migration-portability-notes) for a full breakdown of column type choices and the `INTEGER PRIMARY KEY` / `AUTO_INCREMENT` difference between SQLite and MySQL.

**Key rules when writing new migrations:**
- Use `INTEGER NOT NULL, PRIMARY KEY (id)` for surrogate keys.
- Use `VARCHAR(32)` for datetime columns (ISO strings); avoid engine-specific `DATETIME` or `TIMESTAMP`.
- Use `INTEGER NOT NULL DEFAULT 0` for booleans; never `TINYINT(1)` or `BOOLEAN`.
- Use `CREATE TABLE IF NOT EXISTS` and `CREATE [UNIQUE] INDEX IF NOT EXISTS` for idempotency.
- Use `FOREIGN KEY … ON DELETE CASCADE` for cascading deletes (enabled by SQLite pragma and standard on MySQL).

---

## Seeds

Seeds are separate from migrations. They insert initial data and are designed to be re-run safely.

**Location:** `database/seeds/`  
**CLI:** `php scripts/seed.php [ClassName]`

Each seed class has a `run(DatabaseInterface $db): array` method that returns a log of actions taken. Seeds use `SELECT` to check for existing records before inserting, making them idempotent.

### Current Seeds

| Class | What it inserts |
|---|---|
| `AdminBootstrap` | `admin` group, 6 base permissions (`admin`, `users.view`, `users.create`, `users.edit`, `users.delete`, `api.access`), all permissions granted to `admin` group |

### Running seeds

```bash
# Run all seeds
php scripts/seed.php

# Run one specific seed
php scripts/seed.php AdminBootstrap
```

---

## See Also

- [schema.md](schema.md) — Full table and column reference
- [database.md](database.md) — DatabaseInterface and driver documentation
