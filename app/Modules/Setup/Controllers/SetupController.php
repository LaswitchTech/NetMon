<?php

namespace App\Modules\Setup\Controllers;

use App\Core\Env;
use App\Modules\Setup\Services\SetupService;

/**
 * Thin HTTP controller for the web-based setup wizard.
 *
 * Handles all /setup/* routes during the pre-install phase.
 * All installation logic lives in SetupService; this class handles
 * only HTTP dispatch, session/CSRF management, and JSON I/O.
 *
 * Routes handled:
 *   GET  /setup            Render the wizard shell (wizard.php)
 *   POST /setup/check      Phase 1+2: env + directory checks
 *   POST /setup/db         Phase 3: test database connection
 *   POST /setup/config     Validate app settings, store in session
 *   POST /setup/admin      Validate admin fields, store in session
 *   POST /setup/install    Phases 4–10: write config + run full install
 */
class SetupController
{
    private SetupService $setup;
    private string $viewsPath;

    public function __construct(string $rootPath)
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $this->setup     = new SetupService($rootPath);
        $this->viewsPath = __DIR__ . '/../Views';
    }

    // -------------------------------------------------------------------------
    // Dispatch
    // -------------------------------------------------------------------------

    public function dispatch(string $method, string $path): void
    {
        match (true) {
            $method === 'GET'  && $path === '/setup'          => $this->handleGetWizard(),
            $method === 'POST' && $path === '/setup/check'    => $this->handlePostCheck(),
            $method === 'POST' && $path === '/setup/db'       => $this->handlePostDb(),
            $method === 'POST' && $path === '/setup/config'   => $this->handlePostConfig(),
            $method === 'POST' && $path === '/setup/admin'    => $this->handlePostAdmin(),
            $method === 'POST' && $path === '/setup/install'  => $this->handlePostInstall(),
            $method === 'GET'  && $path === '/setup/done'     => $this->handleGetDone(),
            default                                           => $this->notFound(),
        };
    }

    // -------------------------------------------------------------------------
    // GET /setup — render wizard shell
    // -------------------------------------------------------------------------

    private function handleGetWizard(): void
    {
        // Regenerate session ID to prevent fixation attacks
        session_regenerate_id(true);

        $_SESSION['__setup'] = [
            'token' => bin2hex(random_bytes(32)),
        ];

        $csrfToken  = $_SESSION['__setup']['token'];
        $currentUrl = Env::get('APP_URL', 'http://localhost');
        $appName    = Env::get('APP_NAME', 'NetMon');

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        require $this->viewsPath . '/wizard.php';
    }

    // -------------------------------------------------------------------------
    // GET /setup/done — installation complete confirmation
    // -------------------------------------------------------------------------

    private function handleGetDone(): void
    {
        $appName    = Env::get('APP_NAME', 'NetMon');
        $currentUrl = Env::get('APP_URL', '');

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        echo <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head>
          <meta charset="utf-8">
          <meta name="viewport" content="width=device-width, initial-scale=1">
          <title>Setup Complete &mdash; {$appName}</title>
          <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
          <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
        </head>
        <body class="bg-light">
          <div class="container py-5" style="max-width:520px">
            <div class="card shadow-sm">
              <div class="card-body p-4 text-center">
                <i class="bi bi-check-circle-fill text-success" style="font-size:3.5rem"></i>
                <h1 class="h4 fw-semibold mt-3 mb-2">Installation Complete</h1>
                <p class="text-muted mb-4">{$appName} has been installed successfully.</p>
                <a href="{$currentUrl}/" class="btn btn-primary">
                  <i class="bi bi-box-arrow-in-right me-1"></i> Go to Application
                </a>
              </div>
            </div>
          </div>
        </body>
        </html>
        HTML;
        exit;
    }

    // -------------------------------------------------------------------------
    // POST /setup/check — environment + directory checks
    // -------------------------------------------------------------------------

    private function handlePostCheck(): void
    {
        $this->requireCsrf();

        $env = $this->setup->checkEnvironment();
        $dir = $this->setup->checkDirectories();
        $ok  = $env['ok'] && $dir['ok'];

        if ($ok) {
            $_SESSION['__setup']['prereqs_ok'] = true;
        }

        $this->json(['ok' => $ok, 'env' => $env, 'dir' => $dir]);
    }

    // -------------------------------------------------------------------------
    // POST /setup/db — test database connection
    // -------------------------------------------------------------------------

    private function handlePostDb(): void
    {
        $this->requireCsrf();

        $data   = $this->jsonInput();
        $driver = $data['driver'] ?? 'sqlite';
        $params = $data['params'] ?? [];

        if ($driver !== 'sqlite') {
            $this->json([
                'ok'    => false,
                'error' => 'MySQL / MariaDB support is not yet available. Please select SQLite.',
            ]);
            return;
        }

        $result = $this->setup->testConnection($driver, $params);

        if ($result['ok']) {
            $_SESSION['__setup']['db'] = ['driver' => $driver, 'params' => $params];
        }

        $this->json($result);
    }

    // -------------------------------------------------------------------------
    // POST /setup/config — validate + store application settings
    // -------------------------------------------------------------------------

    private function handlePostConfig(): void
    {
        $this->requireCsrf();

        $data     = $this->jsonInput();
        $appName  = trim($data['app_name'] ?? '');
        $appUrl   = trim($data['app_url']  ?? '');
        $appEnv   = $data['app_env']   ?? 'production';
        $appDebug = !empty($data['app_debug']);

        $errors = [];

        if ($appName === '') {
            $errors['app_name'] = 'Application name is required.';
        } elseif (strlen($appName) > 100) {
            $errors['app_name'] = 'Application name must be 100 characters or fewer.';
        }

        if ($appUrl === '') {
            $errors['app_url'] = 'Application URL is required.';
        } elseif (!preg_match('/^https?:\/\//i', $appUrl)) {
            $errors['app_url'] = 'URL must start with http:// or https://.';
        }

        if (!in_array($appEnv, ['development', 'production'], true)) {
            $errors['app_env'] = 'Environment must be development or production.';
        }

        if (!empty($errors)) {
            $this->json(['ok' => false, 'errors' => $errors]);
            return;
        }

        // Force debug off in production
        if ($appEnv === 'production') {
            $appDebug = false;
        }

        $_SESSION['__setup']['config'] = [
            'app_name'  => $appName,
            'app_url'   => rtrim($appUrl, '/'),
            'app_env'   => $appEnv,
            'app_debug' => $appDebug,
        ];

        $this->json(['ok' => true]);
    }

    // -------------------------------------------------------------------------
    // POST /setup/admin — validate admin account fields (no DB, no write)
    // -------------------------------------------------------------------------

    private function handlePostAdmin(): void
    {
        $this->requireCsrf();

        $data     = $this->jsonInput();
        $name     = trim($data['display_name']     ?? '');
        $username = trim($data['username']         ?? '');
        $email    = trim($data['email']            ?? '');
        $password = $data['password']              ?? '';
        $confirm  = $data['password_confirm']      ?? '';

        $errors = [];

        if ($name === '') {
            $errors['display_name'] = 'Full name is required.';
        } elseif (strlen($name) < 2 || strlen($name) > 100) {
            $errors['display_name'] = 'Full name must be 2–100 characters.';
        }

        if ($username === '') {
            $errors['username'] = 'Username is required.';
        } elseif (!preg_match('/^[a-zA-Z0-9_]{3,50}$/', $username)) {
            $errors['username'] = 'Username must be 3–50 characters (letters, numbers, underscores).';
        }

        if ($email === '') {
            $errors['email'] = 'Email address is required.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Email address is not valid.';
        }

        if ($password === '') {
            $errors['password'] = 'Password is required.';
        } elseif (strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters.';
        }

        if (empty($errors['password']) && $confirm !== $password) {
            $errors['password_confirm'] = 'Passwords do not match.';
        }

        if (!empty($errors)) {
            $this->json(['ok' => false, 'errors' => $errors]);
            return;
        }

        // Store non-sensitive fields only — raw password is never kept in session
        $_SESSION['__setup']['admin'] = [
            'display_name' => $name,
            'username'     => $username,
            'email'        => strtolower($email),
        ];

        $this->json(['ok' => true]);
    }

    // -------------------------------------------------------------------------
    // POST /setup/install — execute all installation phases
    // -------------------------------------------------------------------------

    private function handlePostInstall(): void
    {
        $this->requireCsrf();

        $data    = $this->jsonInput();
        $session = $_SESSION['__setup'] ?? [];

        $dbSess     = $session['db']     ?? ['driver' => 'sqlite', 'params' => []];
        $configSess = $session['config'] ?? [];
        $adminSess  = $session['admin']  ?? [];

        $driver  = $dbSess['driver'];
        $params  = $dbSess['params'];

        // Phase 4 — Write .env
        try {
            $this->setup->writeEnvSettings([
                'APP_NAME'  => $configSess['app_name']  ?? Env::get('APP_NAME', 'NetMon'),
                'APP_URL'   => $configSess['app_url']   ?? 'http://localhost',
                'APP_ENV'   => $configSess['app_env']   ?? 'production',
                'APP_DEBUG' => ($configSess['app_debug'] ?? false) ? 'true' : 'false',
            ]);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'phase' => 'config', 'error' => 'Failed to write .env: ' . $e->getMessage()]);
            return;
        }

        // Phase 5 — Write config/local.php
        try {
            $this->setup->writeLocalConfig(['database' => ['driver' => $driver]]);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'phase' => 'config', 'error' => 'Failed to write local config: ' . $e->getMessage()]);
            return;
        }

        // Phase 6 — Open database connection
        try {
            $db = $this->setup->buildDatabase($driver, $params);
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'phase' => 'database', 'error' => 'Failed to open database: ' . $e->getMessage()]);
            return;
        }

        // Phase 7 — Run migrations
        $migResult = $this->setup->runMigrations($db);
        if (!$migResult['ok']) {
            $this->json(['ok' => false, 'phase' => 'migrations', 'error' => $migResult['error']]);
            return;
        }

        // Phase 8 — Run seeds
        $seedResult = $this->setup->runSeeds($db, ['AdminBootstrap']);
        if (!$seedResult['ok']) {
            $this->json(['ok' => false, 'phase' => 'seeds', 'error' => $seedResult['error']]);
            return;
        }

        // Phase 9 — Create admin user (password re-submitted from browser; never stored in session)
        $password    = $data['password'] ?? '';
        $adminResult = $this->setup->createAdminUser($db, [
            'display_name'     => $adminSess['display_name'] ?? '',
            'username'         => $adminSess['username']     ?? '',
            'email'            => $adminSess['email']        ?? '',
            'password'         => $password,
            'password_confirm' => $password, // match already validated in /setup/admin
        ]);

        if (!$adminResult['ok']) {
            $this->json([
                'ok'     => false,
                'phase'  => 'admin',
                'errors' => $adminResult['errors'],
                'error'  => $adminResult['error'],
            ]);
            return;
        }

        // Phase 10 — Finalize
        try {
            $this->setup->finalize();
        } catch (\Throwable $e) {
            $this->json(['ok' => false, 'phase' => 'finalize', 'error' => $e->getMessage()]);
            return;
        }

        // Clear wizard session state
        unset($_SESSION['__setup']);

        $this->json([
            'ok'       => true,
            'user_id'  => $adminResult['user_id'],
            'username' => $adminSess['username'] ?? '',
        ]);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Validate the X-CSRF-Token request header against the session token.
     * Terminates with 403 JSON on failure.
     */
    private function requireCsrf(): void
    {
        $received = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        $expected = $_SESSION['__setup']['token'] ?? '';

        if ($received === '' || !hash_equals($expected, $received)) {
            http_response_code(403);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'error' => 'Invalid or missing CSRF token. Please reload the page.']);
            exit;
        }
    }

    /**
     * Emit a JSON response and terminate.
     */
    private function json(array $data): void
    {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($data);
        exit;
    }

    /**
     * Decode the raw JSON request body.
     * Returns an empty array on missing or malformed input.
     */
    private function jsonInput(): array
    {
        $body = file_get_contents('php://input');
        if ($body === false || $body === '') {
            return [];
        }
        $decoded = json_decode($body, true);
        return is_array($decoded) ? $decoded : [];
    }

    private function notFound(): void
    {
        http_response_code(404);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => 'Not found.']);
        exit;
    }
}
