<?php

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Models\AuditLogRepository;
use App\Models\SystemSettingRepository;
use App\Services\SystemSettingService;

/**
 * Admin system settings controller.
 *
 * Routes:
 *   GET  /admin/settings  → show()    Display the settings form
 *   POST /admin/settings  → update()  Validate and save settings
 *
 * Both routes are protected by ['WebAuth', 'WebPermission:admin'].
 *
 * Phase 1 managed settings:
 *   app.name                    Application display name
 *   app.url                     Public-facing URL
 *   notifications.email_enabled Enable/disable email delivery
 *   monitoring.check_interval   Default monitoring check interval (seconds)
 *
 * Validation rules:
 *   app.name              required, max 100 chars
 *   app.url               required, must start with http:// or https://, max 255 chars
 *   email_enabled         checkbox (bool)
 *   check_interval        integer, 5–3600
 *
 * Sensitive values (SMTP credentials, passwords) are never displayed
 * or managed here.
 */
class SystemSettingsController extends Controller
{
    // -------------------------------------------------------------------------
    // GET /admin/settings
    // -------------------------------------------------------------------------

    public function show(array $params = []): void
    {
        [$viewsPath, $appName, $displayName, $permissions] = $this->ctx();

        $service = $this->service();

        $settings = [
            'app_name'           => $service->getString('app.name'),
            'app_url'            => $service->getString('app.url'),
            'email_enabled'      => $service->getBool('notifications.email_enabled'),
            'check_interval'     => $service->getInt('monitoring.check_interval'),
        ];

        $pageTitle     = 'System Settings';
        $activeSection = 'Admin Settings';
        $errors        = [];
        $flash         = $this->popFlash();

        ob_start();
        require $viewsPath . '/admin/settings.php';
        $content = ob_get_clean();

        require $viewsPath . '/layouts/app.php';
    }

    // -------------------------------------------------------------------------
    // POST /admin/settings
    // -------------------------------------------------------------------------

    public function update(array $params = []): void
    {
        [$viewsPath, $appName, $displayName, $permissions] = $this->ctx();

        $service = $this->service();

        $input = [
            'app_name'       => trim($_POST['app_name']       ?? ''),
            'app_url'        => trim($_POST['app_url']        ?? ''),
            'email_enabled'  => isset($_POST['email_enabled']),
            'check_interval' => trim($_POST['check_interval'] ?? ''),
        ];

        $errors = $this->validate($input);

        if (!empty($errors)) {
            // Re-populate $settings from POST for the re-render.
            $settings = [
                'app_name'       => $input['app_name'],
                'app_url'        => $input['app_url'],
                'email_enabled'  => $input['email_enabled'],
                'check_interval' => $input['check_interval'] !== '' ? (int) $input['check_interval'] : $service->getInt('monitoring.check_interval'),
            ];

            $pageTitle     = 'System Settings';
            $activeSection = 'Admin Settings';
            $flash         = null;

            ob_start();
            require $viewsPath . '/admin/settings.php';
            $content = ob_get_clean();

            http_response_code(422);
            require $viewsPath . '/layouts/app.php';
            return;
        }

        // Persist
        $service->set('app.name',                    $input['app_name']);
        $service->set('app.url',                     rtrim($input['app_url'], '/'));
        $service->set('notifications.email_enabled', $input['email_enabled']);
        $service->set('monitoring.check_interval',   (int) $input['check_interval']);

        $actorId = (int) ($this->container->get('principal')['user']['id'] ?? 0);
        $this->auditLog($actorId, 'settings.update', 'system_settings', 0, [
            'app_name'       => $input['app_name'],
            'app_url'        => rtrim($input['app_url'], '/'),
            'email_enabled'  => $input['email_enabled'] ? 'true' : 'false',
            'check_interval' => (int) $input['check_interval'],
        ]);

        $this->flash('success', 'System settings saved.');
        header('Location: /admin/settings');
        exit;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Validate settings input.
     *
     * @return array<string, string>  Field → error message; empty = valid.
     */
    private function validate(array $input): array
    {
        $errors = [];

        if ($input['app_name'] === '') {
            $errors['app_name'] = 'Application name is required.';
        } elseif (strlen($input['app_name']) > 100) {
            $errors['app_name'] = 'Application name must be 100 characters or fewer.';
        }

        if ($input['app_url'] === '') {
            $errors['app_url'] = 'Application URL is required.';
        } elseif (!preg_match('#^https?://#i', $input['app_url'])) {
            $errors['app_url'] = 'Application URL must start with http:// or https://.';
        } elseif (strlen($input['app_url']) > 255) {
            $errors['app_url'] = 'Application URL must be 255 characters or fewer.';
        }

        if ($input['check_interval'] === '') {
            $errors['check_interval'] = 'Check interval is required.';
        } elseif (!ctype_digit($input['check_interval'])) {
            $errors['check_interval'] = 'Check interval must be a positive integer.';
        } elseif ((int) $input['check_interval'] < 5 || (int) $input['check_interval'] > 3600) {
            $errors['check_interval'] = 'Check interval must be between 5 and 3600 seconds.';
        }

        return $errors;
    }

    /**
     * Instantiate the service with fresh repository.
     */
    private function service(): SystemSettingService
    {
        return new SystemSettingService(
            new SystemSettingRepository($this->container->get('db'))
        );
    }

    /**
     * Build standard context variables for views.
     * Returns [$viewsPath, $appName, $displayName, $permissions].
     */
    private function ctx(): array
    {
        $principal   = $this->container->get('principal');
        $config      = $this->container->get('config');
        $viewsPath   = __DIR__ . '/../../Views';
        $appName     = $config['name'] ?? 'NetMon';
        $displayName = ($principal['user']['display_name'] ?? '') !== ''
            ? $principal['user']['display_name']
            : $principal['user']['username'];
        $permissions = $principal['permissions'];

        return [$viewsPath, $appName, $displayName, $permissions];
    }

    /**
     * Write an audit log entry. Fails silently.
     */
    private function auditLog(
        ?int   $actorId,
        string $action,
        string $entityType,
        int    $entityId,
        array  $meta = []
    ): void {
        try {
            (new AuditLogRepository($this->container->get('db')))
                ->log($actorId, $action, $entityType, $entityId, $meta);
        } catch (\Throwable $e) {
            // Intentionally swallowed.
        }
    }

    /**
     * Write a flash message to the session.
     */
    private function flash(string $type, string $message): void
    {
        $_SESSION['admin_flash'] = ['type' => $type, 'message' => $message];
    }

    /**
     * Read and clear the flash message from the session.
     */
    private function popFlash(): ?array
    {
        $flash = $_SESSION['admin_flash'] ?? null;
        unset($_SESSION['admin_flash']);
        return $flash;
    }
}
