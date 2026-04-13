<?php

namespace App\Modules\Setup\Services;

use App\Core\DatabaseInterface;
use App\Core\Installer\ConfigWriter;
use App\Core\Installer\DirectoryChecker;
use App\Core\Installer\EnvironmentChecker;
use App\Core\Installer\InstallLock;
use App\Core\MigrationRunner;
use App\Core\SQLiteDriver;
use App\Models\UserRepository;

/**
 * Orchestrates all installation phases.
 *
 * This service is shared by both the CLI installer (scripts/install.php)
 * and the future web setup wizard (app/Modules/Setup/Controllers/).
 * It contains no NetMon-specific logic; application-specific values
 * (seed class names, admin group name) are passed in by the caller.
 *
 * Every public method returns a structured array so the caller can
 * present results in any format (CLI text or JSON for the web wizard).
 *
 * Execution order:
 *   1. checkEnvironment()
 *   2. checkDirectories()
 *   3. testConnection()           (driver + params from caller)
 *   4. writeEnvSettings()         (APP_URL, etc.)
 *   5. writeLocalConfig()         (database driver + credentials)
 *   6. buildDatabase()            (open the actual connection)
 *   7. runMigrations()
 *   8. runSeeds()
 *   9. createAdminUser()
 *  10. finalize()
 */
class SetupService
{
    private string $rootPath;
    private string $envPath;
    private string $configPath;
    private string $storagePath;
    private string $migrationsDir;
    private string $seedsDir;

    private EnvironmentChecker $envChecker;
    private DirectoryChecker   $dirChecker;
    private ConfigWriter       $configWriter;
    private InstallLock        $lock;

    // -------------------------------------------------------------------------

    public function __construct(string $rootPath)
    {
        $this->rootPath      = rtrim($rootPath, DIRECTORY_SEPARATOR);
        $this->envPath       = $this->rootPath . '/.env';
        $this->configPath    = $this->rootPath . '/config';
        $this->storagePath   = $this->rootPath . '/storage';
        $this->migrationsDir = $this->rootPath . '/database/migrations';
        $this->seedsDir      = $this->rootPath . '/database/seeds';

        $this->envChecker   = new EnvironmentChecker();
        $this->dirChecker   = new DirectoryChecker([
            'storage' => $this->storagePath,
            'data'    => $this->rootPath . '/data',
            'config'  => $this->configPath,
        ]);
        $this->configWriter = new ConfigWriter();
        $this->lock         = new InstallLock($this->storagePath);
    }

    // -------------------------------------------------------------------------
    // Phase 1 — Environment check
    // -------------------------------------------------------------------------

    /**
     * @return array{ok: bool, checks: array}
     */
    public function checkEnvironment(): array
    {
        return $this->envChecker->check();
    }

    // -------------------------------------------------------------------------
    // Phase 2 — Directory check
    // -------------------------------------------------------------------------

    /**
     * @return array{ok: bool, paths: array}
     */
    public function checkDirectories(): array
    {
        return $this->dirChecker->check();
    }

    // -------------------------------------------------------------------------
    // Phase 3 — Database connection test (before writing anything)
    // -------------------------------------------------------------------------

    /**
     * Attempt to open a database connection without persisting any config.
     * Returns ok=true on success; ok=false with an error message on failure.
     *
     * @param  string $driver  'sqlite' | 'mysql'
     * @param  array  $params  Driver-specific connection parameters.
     *                         SQLite:  ['path' => '/abs/path/to/db']
     *                         MySQL:   ['host', 'port', 'name', 'user', 'pass', 'charset']
     * @return array{ok: bool, error: string|null}
     */
    public function testConnection(string $driver, array $params): array
    {
        try {
            $this->buildDatabase($driver, $params);
            return ['ok' => true, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => $this->sanitizeDbError($e->getMessage())];
        }
    }

    // -------------------------------------------------------------------------
    // Phase 4 — Write .env settings
    // -------------------------------------------------------------------------

    /**
     * Update specific keys in the .env file.
     * Only the keys present in $values are modified; all other lines preserved.
     *
     * Typical values written during install:
     *   ['APP_URL' => 'https://example.com']
     *
     * APP_INSTALLED is intentionally excluded here — it is written only at
     * the finalize() step after everything else succeeds.
     *
     * @param  array<string,string> $values
     * @throws \RuntimeException on write failure.
     */
    public function writeEnvSettings(array $values): void
    {
        $this->configWriter->writeEnv($this->envPath, $values);
    }

    // -------------------------------------------------------------------------
    // Phase 5 — Write config/local.php
    // -------------------------------------------------------------------------

    /**
     * Write (or overwrite) config/local.php with the provided config array.
     *
     * Example for SQLite:
     *   ['database' => ['driver' => 'sqlite']]
     *
     * Example for MySQL (future):
     *   ['database' => ['driver' => 'mysql', 'mysql' => ['host' => '...', ...]]]
     *
     * @throws \RuntimeException on write failure.
     */
    public function writeLocalConfig(array $config): void
    {
        $this->configWriter->writeLocalPhp(
            $this->configPath . '/local.php',
            $config
        );
    }

    // -------------------------------------------------------------------------
    // Phase 6 — Open database connection
    // -------------------------------------------------------------------------

    /**
     * Build and return a live DatabaseInterface instance.
     *
     * For SQLite the default path is resolved relative to the project root
     * so callers do not need to know the full path.
     *
     * @param  string $driver  'sqlite' | 'mysql'
     * @param  array  $params  Driver-specific connection parameters.
     * @return DatabaseInterface
     * @throws \RuntimeException for unsupported drivers or connection failure.
     */
    public function buildDatabase(string $driver, array $params = []): DatabaseInterface
    {
        return match ($driver) {
            'sqlite' => new SQLiteDriver(
                $params['path'] ?? $this->rootPath . '/data/app.db'
            ),
            default  => throw new \RuntimeException(
                "Database driver '{$driver}' is not yet supported."
            ),
        };
    }

    // -------------------------------------------------------------------------
    // Phase 7 — Run migrations
    // -------------------------------------------------------------------------

    /**
     * Apply all pending migrations.
     *
     * @return array{ok: bool, applied: string[], error: string|null}
     */
    public function runMigrations(DatabaseInterface $db): array
    {
        try {
            $runner  = new MigrationRunner($db, $this->migrationsDir);
            $applied = $runner->run();

            return ['ok' => true, 'applied' => $applied, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'applied' => [], 'error' => $e->getMessage()];
        }
    }

    // -------------------------------------------------------------------------
    // Phase 8 — Run seeds
    // -------------------------------------------------------------------------

    /**
     * Run a list of seed classes from the seeds directory.
     *
     * Each entry in $seedClasses must be a class name (without .php) that
     * exists as a file in the seeds directory and has a run() method.
     *
     * Seeds are idempotent — safe to run more than once.
     *
     * @param  string[] $seedClasses  e.g. ['AdminBootstrap']
     * @return array{ok: bool, log: string[], error: string|null}
     */
    public function runSeeds(DatabaseInterface $db, array $seedClasses): array
    {
        $log = [];

        try {
            foreach ($seedClasses as $className) {
                $file = $this->seedsDir . '/' . $className . '.php';

                if (!file_exists($file)) {
                    throw new \RuntimeException(
                        "Seed file not found: {$file}"
                    );
                }

                require_once $file;

                if (!class_exists($className)) {
                    throw new \RuntimeException(
                        "Seed class '{$className}' not found in {$file}"
                    );
                }

                $seed = new $className($db);

                if (!method_exists($seed, 'run')) {
                    throw new \RuntimeException(
                        "Seed class '{$className}' has no run() method"
                    );
                }

                $log = array_merge($log, $seed->run());
            }

            return ['ok' => true, 'log' => $log, 'error' => null];
        } catch (\Throwable $e) {
            return ['ok' => false, 'log' => $log, 'error' => $e->getMessage()];
        }
    }

    // -------------------------------------------------------------------------
    // Phase 9 — Create administrator account
    // -------------------------------------------------------------------------

    /**
     * Create the first user account and assign it to the admin group.
     *
     * Validation performed:
     *   - All fields required
     *   - Username: 3–50 chars, alphanumeric + underscore, unique in users table
     *   - Email: valid format, unique in users table
     *   - Password: minimum 8 characters
     *   - Passwords match
     *
     * The raw password is hashed immediately and not retained.
     *
     * @param  DatabaseInterface $db
     * @param  array{
     *           display_name: string,
     *           username:     string,
     *           email:        string,
     *           password:     string,
     *           password_confirm: string
     *         } $data
     * @param  string $adminGroup  Name of the admin group (default: 'admin')
     * @return array{ok: bool, user_id: int|null, errors: array, error: string|null}
     */
    public function createAdminUser(
        DatabaseInterface $db,
        array $data,
        string $adminGroup = 'admin'
    ): array {
        // Field-level validation
        $errors = $this->validateAdminData($db, $data);

        if (!empty($errors)) {
            return [
                'ok'      => false,
                'user_id' => null,
                'errors'  => $errors,
                'error'   => 'Validation failed.',
            ];
        }

        try {
            $userRepo = new UserRepository($db);
            $hash     = password_hash($data['password'], PASSWORD_DEFAULT);

            $userId = $userRepo->create([
                'display_name'  => trim($data['display_name']),
                'username'      => trim($data['username']),
                'email'         => strtolower(trim($data['email'])),
                'password_hash' => $hash,
                'is_active'     => 1,
            ]);

            // Assign to admin group
            $group = $db->fetchOne(
                'SELECT id FROM groups WHERE name = ? LIMIT 1',
                [$adminGroup]
            );

            if ($group === null) {
                throw new \RuntimeException(
                    "Admin group '{$adminGroup}' not found. "
                    . 'Run seeds before creating the admin user.'
                );
            }

            $db->execute(
                'INSERT INTO user_groups (user_id, group_id) VALUES (?, ?)',
                [$userId, (int) $group['id']]
            );

            return [
                'ok'      => true,
                'user_id' => $userId,
                'errors'  => [],
                'error'   => null,
            ];
        } catch (\Throwable $e) {
            return [
                'ok'      => false,
                'user_id' => null,
                'errors'  => [],
                'error'   => $e->getMessage(),
            ];
        }
    }

    // -------------------------------------------------------------------------
    // Phase 10 — Finalize
    // -------------------------------------------------------------------------

    /**
     * Write both installation lock signals to mark installation as complete.
     *
     * 1. Writes /storage/installed.lock
     * 2. Updates APP_INSTALLED=true in .env
     *
     * @throws \RuntimeException if either write fails.
     */
    public function finalize(): void
    {
        $this->lock->write();
        $this->configWriter->writeEnv($this->envPath, ['APP_INSTALLED' => 'true']);
    }

    // -------------------------------------------------------------------------
    // Accessors (useful for the CLI / web wizard)
    // -------------------------------------------------------------------------

    public function isAlreadyInstalled(): bool
    {
        return $this->lock->isInstalled();
    }

    public function getEnvPath(): string
    {
        return $this->envPath;
    }

    public function getLocalConfigPath(): string
    {
        return $this->configPath . '/local.php';
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Validate admin account input.
     * Returns an array of field => message pairs, empty on success.
     */
    private function validateAdminData(DatabaseInterface $db, array $data): array
    {
        $errors = [];

        // display_name
        $name = trim($data['display_name'] ?? '');
        if ($name === '') {
            $errors['display_name'] = 'Full name is required.';
        } elseif (strlen($name) < 2 || strlen($name) > 100) {
            $errors['display_name'] = 'Full name must be 2–100 characters.';
        }

        // username
        $username = trim($data['username'] ?? '');
        if ($username === '') {
            $errors['username'] = 'Username is required.';
        } elseif (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $username)) {
            $errors['username'] = 'Username must be 3–50 characters (letters, numbers, underscores).';
        } else {
            $existing = $db->fetchOne(
                'SELECT id FROM users WHERE username = ? LIMIT 1',
                [$username]
            );
            if ($existing !== null) {
                $errors['username'] = "Username '{$username}' is already taken.";
            }
        }

        // email
        $email = trim($data['email'] ?? '');
        if ($email === '') {
            $errors['email'] = 'Email address is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Email address is not valid.';
        } else {
            $existing = $db->fetchOne(
                'SELECT id FROM users WHERE email = ? LIMIT 1',
                [strtolower($email)]
            );
            if ($existing !== null) {
                $errors['email'] = "Email '{$email}' is already registered.";
            }
        }

        // password
        $password = $data['password'] ?? '';
        if ($password === '') {
            $errors['password'] = 'Password is required.';
        } elseif (strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters.';
        }

        // password confirm
        $confirm = $data['password_confirm'] ?? '';
        if (empty($errors['password']) && $confirm !== $password) {
            $errors['password_confirm'] = 'Passwords do not match.';
        }

        return $errors;
    }

    /**
     * Remove potentially sensitive details from a database error message
     * before displaying it to the user.
     */
    private function sanitizeDbError(string $message): string
    {
        // Strip PDO connection strings that may contain credentials
        $message = preg_replace('/\[.+\]/', '', $message);
        $message = preg_replace('/password=[^\s;]*/i', 'password=***', $message);
        return trim($message);
    }
}
