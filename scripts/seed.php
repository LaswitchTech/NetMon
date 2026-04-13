<?php

/**
 * Seed CLI
 *
 * Usage:
 *   php scripts/seed.php              # run all seeds
 *   php scripts/seed.php AdminBootstrap
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
use App\Core\SQLiteDriver;

Env::load(__DIR__ . '/../.env');

$dbConfig = Config::load('database');

if ($dbConfig['driver'] === 'sqlite') {
    $db = new SQLiteDriver($dbConfig['sqlite']['path']);
} else {
    fwrite(STDERR, "Unsupported database driver: {$dbConfig['driver']}\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Discover and run seeds
// ---------------------------------------------------------------------------
$seedsDir = __DIR__ . '/../database/seeds';
$target   = $argv[1] ?? null;

$files = glob($seedsDir . '/*.php');
sort($files);

if (empty($files)) {
    echo "No seed files found in {$seedsDir}\n";
    exit(0);
}

foreach ($files as $file) {
    $className = basename($file, '.php');

    if ($target !== null && $className !== $target) {
        continue;
    }

    require_once $file;

    if (!class_exists($className)) {
        fwrite(STDERR, "Seed class '{$className}' not found in {$file}\n");
        exit(1);
    }

    echo "Running seed: {$className}\n";

    $seed = new $className($db);

    if (!method_exists($seed, 'run')) {
        fwrite(STDERR, "Seed class '{$className}' has no run() method\n");
        exit(1);
    }

    $log = $seed->run();

    foreach ($log as $line) {
        echo $line . "\n";
    }

    echo "Done: {$className}\n\n";
}
