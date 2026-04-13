# Installer Architecture Blueprint

## Purpose

This document defines the **planned implementation architecture** for the NetMon installer. No installer code exists yet. This blueprint governs where installer-related code should be placed, what is shared vs. application-specific, and what contracts must be designed before implementation begins.

---

## Guiding Principles

- The installer must be **reusable** — nothing in the installer core may know about NetMon specifically.
- Application-specific steps (admin account creation, NetMon seeds) are plugged in via **configuration or extension**, not baked into the installer.
- Both the CLI installer and the web wizard run the **same phase logic** from the same shared classes.
- The installer does **not** require the application Container to be running. It must work before the database exists.
- Installer code follows the same three-layer architecture as the rest of the project.

---

## Proposed Directory Structure

```
app/
├── Core/
│   └── Installer/                   ← Kernel-level installer primitives (zero app knowledge)
│       ├── EnvironmentChecker.php   ← PHP version + extension checks
│       ├── DirectoryChecker.php     ← Writable path checks
│       ├── ConfigWriter.php         ← .env and config/local.php writer
│       └── InstallLock.php          ← Write/check/clear install lock
│
├── Modules/
│   └── Setup/                       ← Reusable web wizard module
│       ├── Controllers/
│       │   └── SetupController.php  ← HTTP handlers for /setup/* routes
│       ├── Services/
│       │   └── SetupService.php     ← Orchestrates all install phases
│       └── Views/
│           └── wizard.php           ← Wizard shell HTML (no framework templating)
│
scripts/
└── install.php                      ← CLI installer entry point

public/
└── index.php                        ← Boot guard: redirect to /setup if not installed
```

NetMon-specific installer logic (admin creation, NetMon seeds) lives in:
```
database/seeds/AdminBootstrap.php    ← already exists; run during Phase 8
app/NetMon/                          ← if NetMon-specific installer steps are needed
```

---

## Layer Assignments

### Kernel (`app/Core/Installer/`)

**Rule:** zero application-specific knowledge.

| Class | Responsibility |
|---|---|
| `EnvironmentChecker` | Check PHP version, required and optional extensions. Returns structured result array. |
| `DirectoryChecker` | Check that each required path exists and is writable (or can be created). Returns per-path results. |
| `ConfigWriter` | Write `.env` file and `config/local.php`. Accepts key-value arrays; handles escaping and formatting. Does not know what the values mean. |
| `InstallLock` | Write `storage/installed.lock`, clear it, and check its presence. Also manages the `APP_INSTALLED` flag in `.env` via `ConfigWriter`. |

These four classes are all the installer needs from the kernel. They have no dependencies on `Container`, `Router`, `DatabaseInterface`, or any auth code.

---

### Module Layer (`app/Modules/Setup/`)

**Rule:** knows about the kernel and the auth module schema, but not about NetMon.

#### `SetupService`

The central orchestrator. Responsible for executing all ten installation phases in order. Accepts injected dependencies (database driver, config paths, seed class list) rather than discovering them itself.

Proposed public interface:

```php
class SetupService
{
    public function checkEnvironment(): array;       // Phase 1
    public function checkDirectories(): array;       // Phase 2
    public function testConnection(array $db): bool; // Phase 4
    public function writeConfig(array $app, array $db): void;  // Phases 5+6
    public function runMigrations(): array;          // Phase 7
    public function runSeeds(array $seedClasses): array;       // Phase 8
    public function createAdminUser(array $data): void;        // Phase 9
    public function finalize(): void;                // Phase 10
}
```

Phase 3 (driver selection) is input collection only — no service method needed.

#### `SetupController`

Thin HTTP controller. Maps `POST /setup/*` requests to `SetupService` methods, formats responses as JSON.

No business logic in the controller. Each handler validates its input, calls one service method, returns `{ ok: bool, ... }`.

#### `Views/wizard.php`

A self-contained PHP file that outputs the wizard HTML shell. Minimal inline styles — does not rely on the main application LESS build being compiled.

---

### Application Layer (`app/NetMon/`)

**Rule:** NetMon-specific only.

The only NetMon-specific installer concern is which seed files to run. This is configured, not coded:

```php
// In the installer invocation (CLI or SetupController):
$seedClasses = ['AdminBootstrap'];  // NetMon passes this list; SetupService runs them
$setupService->runSeeds($seedClasses);
```

As NetMon grows, additional seeds (e.g., default monitoring profiles) would be added to this list.

Admin account creation (Phase 9) is handled by `SetupService` using `UserRepository` and `LocalAuthProvider` from the existing auth module. It is generic — any application with the same auth schema can use it.

---

## Boot Guard

The boot guard in `public/index.php` must be implemented before the installer is built. It is the gating mechanism that routes uninstalled deployments to the wizard.

Placement: immediately after autoloader registration, before any Container or Router initialization.

```php
// Pseudocode — not yet implemented
$lock     = new App\Core\Installer\InstallLock(__DIR__ . '/../storage', __DIR__ . '/../.env');
$isCli    = PHP_SAPI === 'cli';
$isSetup  = str_starts_with($uri, '/setup');

if (!$lock->isInstalled() && !$isCli && !$isSetup) {
    header('Location: /setup');
    exit;
}

if ($lock->isInstalled() && $isSetup) {
    http_response_code(403);
    exit('Forbidden — application is already installed.');
}
```

The guard must handle two cases:
1. Not installed + not on `/setup` → redirect to wizard
2. Installed + on `/setup` → 403

---

## CLI Installer Entry Point (`scripts/install.php`)

The CLI installer is a thin script, not a framework application. It:
1. Registers the autoloader (same pattern as `scripts/migrate.php`)
2. Instantiates `SetupService` with the same dependencies as the web wizard
3. Collects input interactively (readline prompts) or from CLI flags (`--no-interaction` mode)
4. Calls `SetupService` methods in phase order
5. Prints structured progress output
6. Exits with documented exit codes

The CLI installer shares **no code** with the web wizard's HTTP layer. It only shares `SetupService` and the kernel installer classes.

---

## Configuration Architecture

### `.env`

Application-level, stable per deployment. Written by `ConfigWriter`.

```
APP_NAME=NetMon
APP_ENV=production
APP_DEBUG=false
APP_URL=https://example.com
APP_INSTALLED=true
```

`Config::load()` must be updated to read `.env` before returning config arrays. Currently `config/app.php` has these values hardcoded.

---

### `config/local.php`

Environment-specific, mutable. Written by `ConfigWriter`. Merged over `config/database.php` at boot.

```php
<?php
return [
    'database' => [
        'driver' => 'sqlite',
        'sqlite' => ['path' => __DIR__ . '/../data/app.db'],
    ],
];
```

The merge happens in `public/index.php` (or wherever `Config::load()` is called). If `config/local.php` exists, its values override the corresponding keys in `config/database.php`.

This merge must be implemented before the installer can persist DB configuration.

---

## Shared Contracts

The following contracts exist or must be defined before implementation:

### `EnvironmentChecker` result shape

```php
[
    'ok'     => bool,
    'checks' => [
        ['label' => 'PHP 8.1+',       'status' => 'pass|fail|optional', 'detail' => '...'],
        ['label' => 'ext-pdo',        'status' => 'pass|fail'],
        ['label' => 'ext-pdo_sqlite', 'status' => 'pass|fail'],
        // ...
    ],
]
```

### `DirectoryChecker` result shape

```php
[
    'ok'    => bool,
    'paths' => [
        ['path' => '/storage', 'status' => 'ok|error', 'fix' => 'chmod 775 storage/'],
        // ...
    ],
]
```

### `SetupService::runMigrations()` result shape

```php
[
    'ok'      => bool,
    'applied' => ['0001_create_migrations_table', ...],
    'error'   => null,  // or error message string
]
```

### `SetupService::runSeeds()` result shape

```php
[
    'ok'  => bool,
    'log' => ['seeded group: admin', 'granted: admin -> users.view', ...],
]
```

---

## Implementation Order

When the time comes to implement the installer, the recommended order is:

1. **`InstallLock`** — needed for the boot guard immediately
2. **Boot guard in `index.php`** — blocks normal app boot if not installed
3. **`.env` support in `Config::load()`** — needed before ConfigWriter can work
4. **`config/local.php` merge** — needed before DB config can be persisted
5. **`EnvironmentChecker` + `DirectoryChecker`** — isolated, no dependencies
6. **`ConfigWriter`** — depends on `.env` support
7. **`SetupService`** — orchestrates all existing components
8. **`scripts/install.php`** — CLI thin wrapper around `SetupService`
9. **`app/Modules/Setup/`** — web wizard controller, service wiring, wizard HTML

The kernel classes (steps 1–6) can be built and unit-tested independently of the web wizard.

---

## Deferred Decisions and Risks

| Topic | Risk | Notes |
|---|---|---|
| `.env` parsing | `Config::load()` currently reads PHP arrays only. A lightweight `.env` parser is needed. Must not introduce a Composer dependency. | Write a simple line-by-line parser; skip comments, split on first `=`. |
| `config/local.php` merge | Merge logic in `index.php` must not break existing config access patterns. | Deep merge only the keys that `local.php` provides; do not wipe unspecified keys. |
| Wizard CSRF | One-time setup token must be generated at `GET /setup` and validated on every `POST`. | Store in `$_SESSION['__setup']['token']`; rotate after use. |
| Wizard state loss on server restart | PHP session may be lost if server restarts mid-wizard. | Acceptable for MVP — user simply restarts the wizard. |
| MySQLDriver | Phase 4 connection test requires `MySQLDriver` or at least a raw PDO attempt. | Raw PDO attempt is sufficient for the installer; `MySQLDriver` can follow. |
| Parallel installs | Two simultaneous wizard sessions could create race conditions on lock write. | Acceptable for single-server deployments. If needed, use an exclusive `fopen` lock. |
| Upgrade path | The installer handles fresh installs only. Running migrations on an already-installed app is handled by `php scripts/migrate.php`. | Document clearly — the installer must refuse to run if already installed. |
| Config rollback on failure | If Phase 7 (migrations) fails after Phase 6 wrote `local.php`, the config is persisted but the DB is incomplete. | Acceptable: the installer is re-runnable. `MigrationRunner` is idempotent. |

---

## See Also

- [install.md](install.md) — User-facing installation guide and phase reference
- [setup-wizard.md](setup-wizard.md) — Web wizard UX and endpoint flow
- [architecture.md](architecture.md) — Three-layer codebase architecture
- [migrations.md](migrations.md) — Migration system
- [database.md](database.md) — Database abstraction layer
