<?php

namespace App\Core;

class MigrationRunner
{
    private DatabaseInterface $db;
    private string            $migrationsDir;

    public function __construct(DatabaseInterface $db, string $migrationsDir)
    {
        $this->db            = $db;
        $this->migrationsDir = rtrim($migrationsDir, '/');
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Run all pending migrations in ascending filename order.
     * Returns a list of migration names that were applied.
     */
    public function run(): array
    {
        $this->ensureTable();

        $pending = $this->pending();

        if (empty($pending)) {
            return [];
        }

        $applied = [];

        foreach ($pending as $file) {
            $name  = $this->nameFromFile($file);
            $class = $this->classFromFile($file);

            require_once $file;

            if (!class_exists($class)) {
                throw new \RuntimeException("Migration class '{$class}' not found in {$file}");
            }

            $migration = new $class($this->db);

            if (!($migration instanceof Migration)) {
                throw new \RuntimeException("Migration '{$class}' must extend App\\Core\\Migration");
            }

            $migration->up();

            $this->record($name);
            $applied[] = $name;
        }

        return $applied;
    }

    /**
     * Roll back the last batch of applied migrations.
     * Returns a list of migration names that were reversed.
     */
    public function rollback(): array
    {
        $this->ensureTable();

        $last = $this->lastBatch();

        if (empty($last)) {
            return [];
        }

        // Roll back in reverse order
        $reversed = array_reverse($last);
        $done     = [];

        foreach ($reversed as $name) {
            $file  = $this->migrationsDir . '/' . $name . '.php';
            $class = $this->classFromName($name);

            if (!file_exists($file)) {
                throw new \RuntimeException("Migration file not found for rollback: {$file}");
            }

            require_once $file;

            if (!class_exists($class)) {
                throw new \RuntimeException("Migration class '{$class}' not found in {$file}");
            }

            $migration = new $class($this->db);

            // Remove the record before calling down() so that migrations which
            // drop the migrations table itself (0001) do not leave the runner
            // trying to DELETE from a table that no longer exists.
            $this->db->execute(
                'DELETE FROM migrations WHERE name = ?',
                [$name]
            );

            $migration->down();

            $done[] = $name;
        }

        return $done;
    }

    /**
     * Return all migration names that have already been applied.
     */
    public function applied(): array
    {
        $this->ensureTable();

        $rows = $this->db->fetch('SELECT name FROM migrations ORDER BY batch ASC, id ASC');

        return array_column($rows, 'name');
    }

    /**
     * Return pending migration files (sorted, not yet applied).
     */
    public function pending(): array
    {
        $applied = $this->applied();
        $files   = $this->discoverFiles();
        $pending = [];

        foreach ($files as $file) {
            $name = $this->nameFromFile($file);

            if (!in_array($name, $applied, true)) {
                $pending[] = $file;
            }
        }

        return $pending;
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Create the migrations tracking table if it does not exist.
     * Uses standard SQL compatible with SQLite and MySQL.
     */
    private function ensureTable(): void
    {
        $this->db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                id         INTEGER      NOT NULL,
                name       VARCHAR(255) NOT NULL,
                batch      INTEGER      NOT NULL,
                applied_at VARCHAR(32)  NOT NULL,
                PRIMARY KEY (id)
            )'
        );
    }

    /**
     * Discover all .php files in the migrations directory, sorted by name.
     */
    private function discoverFiles(): array
    {
        $pattern = $this->migrationsDir . '/*.php';
        $files   = glob($pattern);

        if ($files === false) {
            return [];
        }

        sort($files);

        return $files;
    }

    /**
     * Determine the next batch number.
     */
    private function nextBatch(): int
    {
        $row = $this->db->fetchOne('SELECT MAX(batch) AS max_batch FROM migrations');
        return (int) ($row['max_batch'] ?? 0) + 1;
    }

    /**
     * Return all migration names from the last batch.
     */
    private function lastBatch(): array
    {
        $row = $this->db->fetchOne('SELECT MAX(batch) AS max_batch FROM migrations');

        if ($row === null || $row['max_batch'] === null) {
            return [];
        }

        $rows = $this->db->fetch(
            'SELECT name FROM migrations WHERE batch = ? ORDER BY id ASC',
            [(int) $row['max_batch']]
        );

        return array_column($rows, 'name');
    }

    /**
     * Record a successfully applied migration.
     */
    private function record(string $name): void
    {
        $this->db->execute(
            'INSERT INTO migrations (name, batch, applied_at) VALUES (?, ?, ?)',
            [$name, $this->nextBatch(), date('Y-m-d H:i:s')]
        );
    }

    /**
     * Derive the migration name (basename without .php) from a full file path.
     */
    private function nameFromFile(string $file): string
    {
        return basename($file, '.php');
    }

    /**
     * Derive the PHP class name from a migration file path.
     * Convention: 0001_create_users_table -> CreateUsersTable
     */
    private function classFromFile(string $file): string
    {
        return $this->classFromName($this->nameFromFile($file));
    }

    /**
     * Derive the PHP class name from a migration name.
     * Strips the leading numeric prefix, then StudlyCases the remainder.
     */
    private function classFromName(string $name): string
    {
        // Strip leading digits and underscores: "0001_create_users" -> "create_users"
        $stripped = preg_replace('/^\d+_/', '', $name);

        // StudlyCase: "create_users_table" -> "CreateUsersTable"
        return str_replace('_', '', ucwords($stripped, '_'));
    }
}
