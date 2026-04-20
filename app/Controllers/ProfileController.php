<?php

namespace App\Controllers;

use App\Core\Controller;
use App\Modules\Notifications\Models\NotificationPreferenceRepository;

/**
 * Renders and saves user-owned profile settings.
 *
 * Routes:
 *   GET  /profile                              → index()
 *   POST /profile/notification-preferences    → saveNotificationPreferences()
 *
 * The Profile page is the single place where a user manages:
 *   - Account summary (display name, username, email)
 *   - Notification delivery preferences (channel enable/disable)
 *   - API tokens (list, create, revoke — rendered by JS via existing /api/tokens endpoints)
 *
 * This is separate from a future Preferences/Administration area, which will
 * house admin-managed settings such as users, groups, and permissions.
 */
class ProfileController extends Controller
{
    // -------------------------------------------------------------------------
    // GET /profile
    // -------------------------------------------------------------------------

    public function index(array $params = []): void
    {
        $principal = $this->container->get('principal');
        $config    = $this->container->get('config');

        $user        = $principal['user'];
        $permissions = $principal['permissions'];
        $appName     = $config['name'];
        $pageTitle   = 'Profile';
        $activeSection = 'Profile';

        $displayName = ($user['display_name'] ?? '') !== ''
            ? $user['display_name']
            : $user['username'];

        $prefRepo  = new NotificationPreferenceRepository($this->container->get('db'));
        $notifPrefs = $prefRepo->getAllForUser((int) $user['id']);

        $flash = $_SESSION['profile_flash'] ?? null;
        unset($_SESSION['profile_flash']);

        $viewsPath = __DIR__ . '/../Views';

        ob_start();
        require $viewsPath . '/profile/index.php';
        $content = ob_get_clean();

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        require $viewsPath . '/layouts/app.php';
    }

    // -------------------------------------------------------------------------
    // POST /profile/notification-preferences
    // -------------------------------------------------------------------------

    /**
     * Save the current user's notification channel preferences.
     *
     * Accepts form fields: in_app (checkbox), email (checkbox).
     * Absent checkboxes are treated as disabled (standard HTML behaviour).
     */
    public function saveNotificationPreferences(array $params = []): void
    {
        $principal = $this->container->get('principal');
        $user      = $principal['user'];

        $prefs = [
            'in_app' => isset($_POST['in_app']),
            'email'  => isset($_POST['email']),
        ];

        $prefRepo = new NotificationPreferenceRepository($this->container->get('db'));
        $prefRepo->saveAllForUser((int) $user['id'], $prefs);

        $_SESSION['profile_flash'] = [
            'type'    => 'success',
            'message' => 'Notification preferences saved.',
        ];

        header('Location: /profile#notification-preferences');
        exit;
    }
}
