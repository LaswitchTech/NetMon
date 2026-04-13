<?php

/**
 * Migration CLI
 *
 * Usage:
 *   php scripts/migrate.php             # run pending migrations
 *   php scripts/migrate.php rollback    # roll back the last batch
 *   php scripts/migrate.php status      # list applied and pending migrations
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Autoloader
// ---------------------------------------------------------------------------
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    $base   = __DIR__ . '/../app/';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file     = $base . str_replace('\\', '/', $relative) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------
use App\Core\Config;
use App\Core\Env;
use App\Core\MigrationRunner;
use App\Core\SQLiteDriver;

Env::load(__DIR__ . '/../.env');

$dbConfig = Config::load('database');

if ($dbConfig['driver'] === 'sqlite') {
    $db = new SQLiteDriver($dbConfig['sqlite']['path']);
} else {
    fwrite(STDERR, "Unsupported database driver: {$dbConfig['driver']}\n");
    exit(1);
}

$migrationsDir = __DIR__ . '/../database/migrations';
$runner        = new MigrationRunner($db, $migrationsDir);

// ---------------------------------------------------------------------------
// Commands
// ---------------------------------------------------------------------------
$command = $argv[1] ?? 'run';

switch ($command) {

    case 'run':
        $applied = $runner->run();

        if (empty($applied)) {
            echo "Nothing to migrate.\n";
        } else {
            foreach ($applied as $name) {
                echo "  Applied: {$name}\n";
            }
            echo "Done. " . count($applied) . " migration(s) applied.\n";
        }
        break;

    case 'rollback':
        $reversed = $runner->rollback();

        if (empty($reversed)) {
            echo "Nothing to roll back.\n";
        } else {
            foreach ($reversed as $name) {
                echo "  Rolled back: {$name}\n";
            }
            echo "Done. " . count($reversed) . " migration(s) reversed.\n";
        }
        break;

    case 'status':
        $applied = $runner->applied();
        $pending = array_map(
            fn($f) => basename($f, '.php'),
            $runner->pending()
        );

        if (!empty($applied)) {
            echo "Applied:\n";
            foreach ($applied as $name) {
                echo "  [x] {$name}\n";
            }
        }

        if (!empty($pending)) {
            echo "Pending:\n";
            foreach ($pending as $name) {
                echo "  [ ] {$name}\n";
            }
        }

        if (empty($applied) && empty($pending)) {
            echo "No migrations found.\n";
        }
        break;

    default:
        fwrite(STDERR, "Unknown command: {$command}\n");
        fwrite(STDERR, "Usage: php scripts/migrate.php [run|rollback|status]\n");
        exit(1);
}
