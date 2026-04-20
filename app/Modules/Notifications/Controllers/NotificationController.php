<?php

namespace App\Modules\Notifications\Controllers;

use App\Core\Controller;
use App\Modules\Notifications\Models\NotificationRepository;

/**
 * Handles the in-app notification inbox.
 *
 * Routes:
 *   GET  /notifications              → index()         full inbox page (fallback / view-all)
 *   POST /notifications/read-all     → markAllRead()   mark every unread item read
 *   POST /notifications/{id}/read    → markRead()      mark one item read
 *   GET  /api/notifications/count    → unreadCount()   JSON unread badge count
 *   GET  /api/notifications/recent   → recent()        JSON recent items for topbar dropdown
 *
 * markRead() and markAllRead() detect whether the caller expects JSON
 * (Accept: application/json header) and return a JSON response instead of
 * redirecting.  This lets the topbar dropdown update in-place without a page reload.
 *
 * This controller belongs to the reusable Notifications module and has no
 * dependency on any NetMon-specific repository or service.
 */
class NotificationController extends Controller
{
    // -------------------------------------------------------------------------
    // GET /notifications — full inbox page
    // -------------------------------------------------------------------------

    /**
     * Render the notification inbox for the authenticated user.
     *
     * Shows all in_app deliveries (read + unread), newest first.
     * An unread item is highlighted and has a "Mark as read" button.
     */
    public function index(array $params = []): void
    {
        $principal = $this->container->get('principal');
        $config    = $this->container->get('config');

        $user        = $principal['user'];
        $permissions = $principal['permissions'];
        $appName     = $config['name'];
        $pageTitle   = 'Notifications';
        $activeSection = 'Notifications';

        $displayName = ($user['display_name'] ?? '') !== ''
            ? $user['display_name']
            : $user['username'];

        $repo          = new NotificationRepository($this->container->get('db'));
        $notifications = $repo->findByUser((int) $user['id'], 100);
        $unreadCount   = $repo->countUnreadByUser((int) $user['id']);

        $viewsPath = __DIR__ . '/../../../Views';

        ob_start();
        require $viewsPath . '/notifications/index.php';
        $content = ob_get_clean();

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        require $viewsPath . '/layouts/app.php';
    }

    // -------------------------------------------------------------------------
    // POST /notifications/{id}/read
    // -------------------------------------------------------------------------

    /**
     * Mark a single in_app delivery as read.
     *
     * Verifies ownership — only the delivery's recipient can mark it read.
     *
     * When called with Accept: application/json (topbar dropdown AJAX), returns:
     *   {"success": true, "unread_count": N}
     *
     * Otherwise redirects back to the full inbox page.
     */
    public function markRead(array $params = []): void
    {
        $deliveryId = (int) ($params['id'] ?? 0);
        $principal  = $this->container->get('principal');
        $user       = $principal['user'];

        $repo     = new NotificationRepository($this->container->get('db'));
        $delivery = $repo->findDeliveryById($deliveryId);

        if ($delivery !== null && (int) $delivery['user_id'] === (int) $user['id']) {
            $repo->markRead($deliveryId);
        }

        if ($this->wantsJson()) {
            $count = $repo->countUnreadByUser((int) $user['id']);
            $this->json(['success' => true, 'unread_count' => $count]);
            return;
        }

        header('Location: /notifications');
        exit;
    }

    // -------------------------------------------------------------------------
    // POST /notifications/read-all
    // -------------------------------------------------------------------------

    /**
     * Mark all in_app notifications for the current user as read.
     *
     * When called with Accept: application/json, returns:
     *   {"success": true, "unread_count": 0}
     *
     * Otherwise redirects back to the full inbox page.
     */
    public function markAllRead(array $params = []): void
    {
        $principal = $this->container->get('principal');
        $user      = $principal['user'];

        $repo = new NotificationRepository($this->container->get('db'));
        $repo->markAllReadByUser((int) $user['id']);

        if ($this->wantsJson()) {
            $this->json(['success' => true, 'unread_count' => 0]);
            return;
        }

        header('Location: /notifications');
        exit;
    }

    // -------------------------------------------------------------------------
    // GET /api/notifications/count
    // -------------------------------------------------------------------------

    /**
     * Return the unread in_app notification count for the current user as JSON.
     *
     * Called by the topbar badge via AJAX on every page load.
     * Uses SessionAuth (returns 401 JSON on failure, not a redirect).
     *
     * Response: {"count": N}
     */
    public function unreadCount(array $params = []): void
    {
        $principal = $this->container->get('principal');
        $user      = $principal['user'];

        $repo  = new NotificationRepository($this->container->get('db'));
        $count = $repo->countUnreadByUser((int) $user['id']);

        $this->json(['count' => $count]);
    }

    // -------------------------------------------------------------------------
    // GET /api/notifications/recent
    // -------------------------------------------------------------------------

    /**
     * Return the most recent in_app notifications as JSON, for the topbar dropdown.
     *
     * Returns up to 10 items (read + unread) with a simplified payload.
     * Uses SessionAuth (returns 401 JSON on failure, not a redirect).
     *
     * Response: {"notifications": [...], "unread_count": N}
     */
    public function recent(array $params = []): void
    {
        $principal = $this->container->get('principal');
        $user      = $principal['user'];

        $repo  = new NotificationRepository($this->container->get('db'));
        $items = $repo->findByUser((int) $user['id'], 10);
        $count = $repo->countUnreadByUser((int) $user['id']);

        $notifications = array_map(static function (array $n): array {
            return [
                'id'          => (int) $n['id'],
                'title'       => $n['title'],
                'body'        => $n['body'],
                'source_type' => $n['source_type'],
                'created_at'  => $n['created_at'],
                'is_unread'   => $n['read_at'] === null,
            ];
        }, $items);

        $this->json(['notifications' => $notifications, 'unread_count' => $count]);
    }

    // -------------------------------------------------------------------------
    // Private helpers
    // -------------------------------------------------------------------------

    /**
     * Returns true when the caller expects a JSON response.
     *
     * Checks the Accept header for "application/json".
     * Used to support both form-POST (redirect) and AJAX (JSON) call patterns
     * on the same action endpoints.
     */
    private function wantsJson(): bool
    {
        return str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');
    }
}
