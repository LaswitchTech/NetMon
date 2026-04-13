# Configuration System

## Overview

Configuration is loaded from three layered sources in a fixed order. Each layer can override values from the layer below it.

```
┌─────────────────────────────────────────────────┐
│  Layer 3 — config/local.php (optional)          │
│  Deployment-specific overrides. Not committed.  │
├─────────────────────────────────────────────────┤
│  Layer 2 — config/{file}.php (base files)       │
│  Sensible defaults. Version-controlled.         │
├─────────────────────────────────────────────────┤
│  Layer 1 — .env                                 │
│  Long-lived app identity. Not committed.        │
└─────────────────────────────────────────────────┘
```

---

## Loading Order

### Step 1 — `.env` (environment variables)

Loaded once at the top of `public/index.php` (and CLI scripts) via `Env::load()`.

- Populates `$_ENV` and calls `putenv()` for each key
- Silent if `.env` is absent — defaults in config files take over
- Must be loaded before any `Config::load()` call, because base config files read from `getenv()`

Keys defined in `.env`:

| Key | Default | Purpose |
|---|---|---|
| `APP_NAME` | `NetMon` | Application display name |
| `APP_ENV` | `development` | Environment: `development` or `production` |
| `APP_DEBUG` | `true` | Enable debug output and stack traces |
| `APP_URL` | `http://localhost` | Base URL of the application |
| `APP_INSTALLED` | `false` | Installation state flag (managed by the installer) |

`.env` is **not committed to version control**. Use `.env.example` as the canonical template.

---

### Step 2 — `config/{file}.php` (base config files)

Loaded on demand by `Config::load('database')`, `Config::load('app')`, etc.

These files contain sensible defaults and are **version-controlled**. Where appropriate they read from `getenv()` so that `.env` values take effect without modifying the file.

| File | Purpose |
|---|---|
| `config/app.php` | Application identity (name, env, debug, url, installed). Reads from `getenv()`. |
| `config/database.php` | Database driver and connection defaults. |
| `config/auth.php` | Auth provider, session settings. |

---

### Step 3 — `config/local.php` (optional overrides)

Loaded automatically by `Config::load()` whenever it processes any config file. Absent by default — safe to omit.

`local.php` returns an array keyed by config file name. Only the matching key is applied:

```php
// config/local.php
return [
    'database' => [
        'driver' => 'mysql',
        'mysql'  => ['host' => 'db.prod', 'name' => 'netmon', 'user' => 'u', 'pass' => 's'],
    ],
    'auth' => [
        'session' => ['secure' => true],
    ],
];
```

When `Config::load('database')` runs, it loads `config/database.php` as the base, then merges `local.php['database']` over it using `array_replace_recursive`.

**Merge semantics:** scalar values in `local.php` replace their counterpart in the base file. Nested arrays are merged key-by-key rather than replaced wholesale. Example: setting `local.php['database']['mysql']['host']` changes only the host — all other `mysql.*` keys are preserved from the base file.

`config/local.php` is **not committed to version control**. Use `config/local.php.example` as the template.

---

## Config Files Reference

### `config/app.php`

```php
return [
    'name'      => getenv('APP_NAME')      ?: 'NetMon',
    'env'       => getenv('APP_ENV')        ?: 'development',
    'debug'     => (bool) filter_var(getenv('APP_DEBUG')      ?: 'true',  FILTER_VALIDATE_BOOLEAN),
    'url'       => getenv('APP_URL')        ?: 'http://localhost',
    'installed' => (bool) filter_var(getenv('APP_INSTALLED') ?: 'false', FILTER_VALIDATE_BOOLEAN),
];
```

### `config/database.php`

Hardcoded defaults (SQLite by default). Override driver and credentials via `config/local.php`.

### `config/auth.php`

Auth provider and session settings. Override session.secure via `config/local.php` when running HTTPS in production.

---

## Env Class (`App\Core\Env`)

A minimal, zero-dependency `.env` parser.

### `Env::load(string $path): void`

Parses the file at `$path` and populates `$_ENV` / `putenv()`. Safe to call multiple times — subsequent calls are no-ops. Silent if the file is absent.

Supported syntax:
```
APP_NAME=NetMon
APP_NAME="Net Mon"       # double-quoted value
APP_NAME='Net Mon'       # single-quoted value
# This is a comment
```

Not supported: variable interpolation, multi-line values, `export` keyword.

### `Env::get(string $key, mixed $default = null): mixed`

Returns the value for `$key`, checked in order:
1. Values loaded from `.env` by `Env::load()`
2. `getenv($key)` (process environment, e.g. set by the web server or shell)
3. `$default`

### `Env::bool(string $key, bool $default = false): bool`

Returns the value coerced to a boolean. Truthy strings: `true`, `1`, `yes`, `on` (case-insensitive). All other values (including absent keys) return false.

---

## Config Class (`App\Core\Config`)

### `Config::load(string $file): array`

Returns the fully-merged config array for the given file. Results are cached per process — each file is loaded and merged at most once.

### `Config::get(string $file, ?string $key = null, mixed $default = null): mixed`

Convenience method. With `$key = null`, returns the full array. With a key, returns `$array[$key] ?? $default`.

### `Config::flush(): void`

Clears the in-process cache. Intended for tests that re-load config after mutating `local.php`.

---

## Where Each Setting Belongs

| Setting type | Where it lives | Example |
|---|---|---|
| Application identity | `.env` | `APP_NAME`, `APP_URL` |
| Environment/debug mode | `.env` | `APP_ENV`, `APP_DEBUG` |
| Installation state | `.env` | `APP_INSTALLED` |
| Database defaults | `config/database.php` | Default driver, SQLite path |
| Database production credentials | `config/local.php` | MySQL host/user/pass |
| Auth defaults | `config/auth.php` | Session lifetime |
| HTTPS session flag | `config/local.php` | `auth.session.secure = true` |
| App-level overrides | `config/local.php` | `app.debug = false` |

**Rule of thumb:**
- If a value is part of the application's identity and is stable per deployment → `.env`
- If a value is environment-specific and changes between deployments → `config/local.php`
- If a value is a sensible default that works out of the box → `config/{file}.php`

---

## Installation State

`APP_INSTALLED` in `.env` is the logical flag. `/storage/installed.lock` is the filesystem signal.

**Both must be present for the app to be considered installed.**

Managed by `App\Core\Installer\InstallLock`:

```php
$lock = new InstallLock(__DIR__ . '/../storage');
$lock->isInstalled(); // true only when lock file exists AND APP_INSTALLED=true
$lock->write();       // creates the lock file (caller updates .env separately)
$lock->clear();       // removes the lock file
```

See [installer-architecture.md](installer-architecture.md) for the full install-state boot guard design.

---

## Boot Sequence

```
public/index.php
  1. Register autoloader
  2. Env::load('.env')               ← populates getenv()
  3. InstallLock::isInstalled()      ← boot guard, may redirect or exit
  4. Logger::__construct()
  5. Config::load('app')             ← reads from getenv(), merges local.php
  6. ErrorHandler::register()
  7. Config::load('database')        ← merges local.php['database']
  8. Config::load('auth')            ← merges local.php['auth']
  9. Container + services
 10. Router::dispatch()
```

---

## File Reference

| File | Committed | Purpose |
|---|---|---|
| `.env.example` | Yes | Template for `.env` |
| `.env` | **No** | Actual environment variables |
| `config/app.php` | Yes | Base app config (reads from getenv) |
| `config/database.php` | Yes | Base database defaults |
| `config/auth.php` | Yes | Base auth settings |
| `config/local.php.example` | Yes | Template for `local.php` |
| `config/local.php` | **No** | Deployment-specific overrides |

---

## See Also

- [install.md](install.md) — Installation phases and how config is written during install
- [installer-architecture.md](installer-architecture.md) — Boot guard and `InstallLock` design
- [architecture.md](architecture.md) — Three-layer codebase architecture
