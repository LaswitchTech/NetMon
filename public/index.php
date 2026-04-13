<?php

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Imports — resolved at compile time; autoloader handles runtime loading
// ---------------------------------------------------------------------------
use App\Auth\AuthService;
use App\Auth\LocalAuthProvider;
use App\Auth\TokenService;
use App\Core\Config;
use App\Core\Container;
use App\Core\Env;
use App\Core\ErrorHandler;
use App\Core\Gate;
use App\Core\Installer\InstallLock;
use App\Core\Logger;
use App\Core\Router;
use App\Core\SQLiteDriver;
use App\Models\TokenRepository;
use App\Models\UserRepository;
use App\Modules\Setup\Controllers\SetupController;

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
// Environment — must run before Config or InstallLock
// ---------------------------------------------------------------------------
Env::load(__DIR__ . '/../.env');

// ---------------------------------------------------------------------------
// Installation-state boot guard
//
// Runs before the Container or Router are initialized because the database
// may not yet exist on a fresh deployment.
//
// Cases handled:
//   [A] Not installed + non-setup request  → redirect to /setup
//   [B] Not installed + /setup request     → allow (setup placeholder or wizard)
//   [C] Installed     + /setup request     → 403 Forbidden
//   [D] Installed     + non-setup request  → normal application boot (falls through)
// ---------------------------------------------------------------------------

$installLock = new InstallLock(__DIR__ . '/../storage');

$requestUri  = $_SERVER['REQUEST_URI'] ?? '/';
$requestPath = parse_url($requestUri, PHP_URL_PATH) ?? '/';
$isSetupPath = str_starts_with($requestPath, '/setup');
$method      = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if (!$installLock->isInstalled()) {
    if (!$isSetupPath) {
        // [A] Not installed — send user to the setup wizard
        header('Location: /setup', true, 302);
        exit;
    }

    // [B] Not installed and already on /setup — dispatch to the setup wizard
    $rootPath = realpath(__DIR__ . '/..');
    (new SetupController($rootPath))->dispatch($method, $requestPath);
    exit;
}

if ($isSetupPath) {
    // [C] Installed — block all /setup routes permanently
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
    <!DOCTYPE html>
    <html lang="en">
    <head>
      <meta charset="utf-8">
      <title>403 Forbidden</title>
      <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    </head>
    <body class="bg-light">
      <div class="container py-5" style="max-width:520px">
        <div class="card shadow-sm">
          <div class="card-body p-4 text-center">
            <h1 class="h4 mb-2">403 Forbidden</h1>
            <p class="text-muted">
              Setup is not available because this application is already installed.
            </p>
            <a href="/" class="btn btn-primary btn-sm mt-2">Go to application</a>
          </div>
        </div>
      </div>
    </body>
    </html>
    HTML;
    exit;
}

// [D] Installed and normal request — continue with full application boot.

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------

// Error handling — register before any code that can throw
$logger  = new Logger(__DIR__ . '/../storage/logs');
$appCfg  = Config::load('app');
$handler = new ErrorHandler($logger, (bool) ($appCfg['debug'] ?? false));
$handler->register();

// ---------------------------------------------------------------------------
// Container
// ---------------------------------------------------------------------------
$container = new Container();

$container->set('config', $appCfg);
$container->set('logger', $logger);

// Database
$dbConfig = Config::load('database');

if ($dbConfig['driver'] === 'sqlite') {
    $container->set('db', new SQLiteDriver($dbConfig['sqlite']['path']));
} else {
    throw new \RuntimeException("Unsupported database driver: {$dbConfig['driver']}");
}

// Auth
$authConfig = Config::load('auth');
$userRepo   = new UserRepository($container->get('db'));
$tokenRepo  = new TokenRepository($container->get('db'));
$gate       = new Gate($container->get('db'));

$container->set('auth',   new AuthService(new LocalAuthProvider($userRepo), $authConfig));
$container->set('gate',   $gate);
$container->set('tokens', new TokenService($tokenRepo, $userRepo, $gate));

// ---------------------------------------------------------------------------
// Routing
// ---------------------------------------------------------------------------
$router = new Router($container);

require __DIR__ . '/../routes/web.php';

$router->dispatch($method, $requestPath);
