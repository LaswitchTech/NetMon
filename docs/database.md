# NetMon Database Layer

## Overview

All database access goes through a two-layer abstraction:

1. **`DatabaseInterface`** — the contract every driver must implement.
2. **`SQLiteDriver`** — the current implementation, backed by PDO.

No SQL is written in controllers or services directly — queries live in **repository classes** in `app/Models/`.

For the table schema, see → [schema.md](schema.md).

---

## DatabaseInterface

**File:** `app/Core/DatabaseInterface.php`

| Method | Signature | Description |
|---|---|---|
| `fetch` | `(string $sql, array $bindings = []): array` | Execute a SELECT; return all matching rows as associative arrays |
| `fetchOne` | `(string $sql, array $bindings = []): ?array` | Execute a SELECT; return first row or null |
| `execute` | `(string $sql, array $bindings = []): int` | Execute INSERT / UPDATE / DELETE; return affected row count |
| `lastInsertId` | `(): string` | Return the last auto-assigned primary key |
| `pdo` | `(): PDO` | Return the underlying PDO instance for DDL statements |

All query methods use **prepared statements with positional bindings** (`?`). Raw string interpolation is never used.

`pdo()` is reserved for DDL in migration `up()` / `down()` methods. Application code (repositories, services) must not call `pdo()` directly.

---

## SQLiteDriver

**File:** `app/Core/SQLiteDriver.php`  
**Namespace:** `App\Core`

### Connection setup

```php
new SQLiteDriver(string $path)
```

- `$path` is the absolute filesystem path to the `.db` file (from `config/database.php`).
- The parent directory must exist; the driver throws `RuntimeException` if it does not.
- PDO error mode is set to `ERRMODE_EXCEPTION` — all query errors become PHP exceptions.
- Default fetch mode is `FETCH_ASSOC` — all rows returned as `['column' => value]` maps.

### SQLite pragmas enabled at connection

| Pragma | Value | Reason |
|---|---|---|
| `journal_mode` | `WAL` | Better concurrent read performance; safe for multi-process access |
| `foreign_keys` | `ON` | SQLite does not enforce FK constraints by default; this enables them per-connection |

### Database file location

Configured in `config/database.php`:

```php
'sqlite' => [
    'path' => __DIR__ . '/../data/app.db',
]
```

The file is at `/data/app.db` relative to the project root. This directory is not web-accessible.

---

## Configuration

**File:** `config/database.php`

```php
return [
    'driver' => 'sqlite',       // active driver; change to 'mysql' when MySQLDriver is added

    'sqlite' => [
        'path' => __DIR__ . '/../data/app.db',
    ],

    'mysql' => [
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'name'    => 'netmon',
        'user'    => 'root',
        'pass'    => '',
        'charset' => 'utf8mb4',
    ],
];
```

The `mysql` block is a configuration stub. No `MySQLDriver` exists yet.

---

## Repository Pattern

Query logic is grouped into repository classes under `app/Models/`:

| Repository | Table(s) | Key methods |
|---|---|---|
| `UserRepository` | `users` | `findById`, `findByUsername`, `findByEmail` |
| `TokenRepository` | `api_tokens` | `findByHash`, `findByUserId`, `create`, `revoke`, `touchLastUsed` |

Repositories accept a `DatabaseInterface` in their constructor. They return raw PHP arrays — no ORM objects.

### Example query pattern

```php
// Fetch multiple rows
$rows = $this->db->fetch(
    'SELECT * FROM users WHERE is_active = ?',
    [1]
);

// Fetch one row
$user = $this->db->fetchOne(
    'SELECT * FROM users WHERE id = ? AND is_active = 1 LIMIT 1',
    [$id]
);

// Insert / Update / Delete
$affected = $this->db->execute(
    'UPDATE api_tokens SET revoked_at = ? WHERE id = ?',
    [date('Y-m-d H:i:s'), $tokenId]
);

// Last inserted ID
$newId = (int) $this->db->lastInsertId();
```

---

## Adding a MySQLDriver

1. Create `app/Core/MySQLDriver.php` implementing `DatabaseInterface`.
2. Wire it in `public/index.php` by adding an `elseif` branch for `driver === 'mysql'`.
3. No repository, service, or migration code changes are required — they all depend on `DatabaseInterface`.

**Known DDL difference:** SQLite's `INTEGER PRIMARY KEY` auto-increments via rowid. MySQL requires explicit `AUTO_INCREMENT`. Migration files will need a one-line change per table when targeting MySQL. See → [schema.md — Migration Portability Notes](schema.md#migration-portability-notes).

---

## See Also

- [schema.md](schema.md) — Table definitions, indexes, column types
- [migrations.md](migrations.md) — How schema changes are applied
- [architecture.md](architecture.md) — How the database layer fits into the full request lifecycle
