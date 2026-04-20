<?php

namespace App\NetMon\Controllers;

use App\Core\Controller;
use App\Models\AlertRepository;
use App\Models\NotificationRepository;
use App\Modules\Notes\Models\NoteRepository;
use App\Modules\Notes\Services\NoteService;

class AlertController extends Controller
{
    /**
     * GET /alerts[?filter=open|all]
     *
     * Renders the Alerts list page inside the authenticated app shell.
     * Authentication is enforced by the WebAuth middleware applied in routes/web.php.
     *
     * Query params:
     *   filter=open  (default) — show only currently open alerts
     *   filter=all             — show the 100 most recent alerts (open + resolved)
     */
    public function index(array $params = []): void
    {
        $principal   = $this->container->get('principal');
        $config      = $this->container->get('config');

        $user          = $principal['user'];
        $permissions   = $principal['permissions'];
        $appName       = $config['name'];
        $pageTitle     = 'Alerts';
        $activeSection = 'Alerts';

        $displayName = ($user['display_name'] ?? '') !== ''
            ? $user['display_name']
            : $user['username'];

        $filter = $_GET['filter'] ?? 'open';
        if (!in_array($filter, ['open', 'all'], true)) {
            $filter = 'open';
        }

        $alertRepo = new AlertRepository($this->container->get('db'));

        $alerts = $filter === 'all'
            ? $alertRepo->findRecent(100)
            : $alertRepo->findAllOpen();

        $viewsPath = __DIR__ . '/../../Views';

        ob_start();
        require $viewsPath . '/alerts/index.php';
        $content = ob_get_clean();

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        require $viewsPath . '/layouts/app.php';
    }

    /**
     * GET /alerts/{id}
     *
     * Renders the alert detail page.
     */
    public function show(array $params = []): void
    {
        $principal   = $this->container->get('principal');
        $config      = $this->container->get('config');

        $user          = $principal['user'];
        $permissions   = $principal['permissions'];
        $appName       = $config['name'];
        $activeSection = 'Alerts';

        $displayName = ($user['display_name'] ?? '') !== ''
            ? $user['display_name']
            : $user['username'];

        $alertId   = (int) ($params['id'] ?? 0);
        $alertRepo = new AlertRepository($this->container->get('db'));
        $alert     = $alertRepo->findById($alertId);

        if ($alert === null) {
            http_response_code(404);
            $pageTitle   = 'Alert not found';
            $viewsPath   = __DIR__ . '/../../Views';
            ob_start();
            echo '<div class="container py-5 text-center text-muted"><p>Alert #' . $alertId . ' was not found.</p></div>';
            $content = ob_get_clean();
            header('Content-Type: text/html; charset=utf-8');
            require $viewsPath . '/layouts/app.php';
            return;
        }

        $pageTitle     = 'Alert #' . $alertId;

        $notifRepo     = new NotificationRepository($this->container->get('db'));
        $notifications = $notifRepo->findRecentByAlert($alertId, 20);

        $noteRepo = new NoteRepository($this->container->get('db'));
        $notes    = $noteRepo->findByEntity('alert', $alertId);

        $viewsPath = __DIR__ . '/../../Views';

        ob_start();
        require $viewsPath . '/alerts/show.php';
        $content = ob_get_clean();

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        require $viewsPath . '/layouts/app.php';
    }

    /**
     * POST /alerts/{id}/notes
     *
     * Adds a note to the alert. Redirects back to /alerts/{id}#notes.
     */
    public function addNote(array $params = []): void
    {
        $alertId   = (int) ($params['id'] ?? 0);
        $alertRepo = new AlertRepository($this->container->get('db'));
        $alert     = $alertRepo->findById($alertId);

        if ($alert === null) {
            http_response_code(404);
            echo 'Alert not found.';
            return;
        }

        $principal = $this->container->get('principal');
        $user      = $principal['user'];
        $content   = $_POST['content'] ?? '';

        $noteRepo = new NoteRepository($this->container->get('db'));
        $service  = new NoteService($noteRepo);

        try {
            $service->addNote('alert', $alertId, (int) $user['id'], $content);
            header('Location: /alerts/' . $alertId . '#notes');
        } catch (\InvalidArgumentException $e) {
            header('Location: /alerts/' . $alertId . '?note_error=' . urlencode($e->getMessage()) . '#notes');
        }

        exit;
    }

    /**
     * POST /alerts/{id}/notes/{noteId}/delete
     *
     * Deletes a note from the alert (author-only). Redirects back to /alerts/{id}#notes.
     */
    public function deleteNote(array $params = []): void
    {
        $alertId  = (int) ($params['id']     ?? 0);
        $noteId   = (int) ($params['noteId'] ?? 0);

        $alertRepo = new AlertRepository($this->container->get('db'));
        $alert     = $alertRepo->findById($alertId);

        if ($alert === null) {
            http_response_code(404);
            echo 'Alert not found.';
            return;
        }

        $principal = $this->container->get('principal');
        $user      = $principal['user'];

        $noteRepo = new NoteRepository($this->container->get('db'));
        $service  = new NoteService($noteRepo);

        try {
            $service->removeNote($noteId, (int) $user['id']);
            header('Location: /alerts/' . $alertId . '#notes');
        } catch (\InvalidArgumentException $e) {
            header('Location: /alerts/' . $alertId . '?note_error=' . urlencode($e->getMessage()) . '#notes');
        }

        exit;
    }

    /**
     * POST /alerts/{id}/acknowledge
     *
     * Acknowledges an open alert, then redirects back to the detail page.
     */
    public function acknowledge(array $params = []): void
    {
        $alertId   = (int) ($params['id'] ?? 0);
        $alertRepo = new AlertRepository($this->container->get('db'));
        $alertRepo->acknowledge($alertId);

        header('Location: /alerts/' . $alertId);
        exit;
    }

    /**
     * POST /alerts/{id}/suppress
     *
     * Suppresses an open alert, then redirects back to the detail page.
     */
    public function suppress(array $params = []): void
    {
        $alertId   = (int) ($params['id'] ?? 0);
        $alertRepo = new AlertRepository($this->container->get('db'));
        $alertRepo->suppress($alertId);

        header('Location: /alerts/' . $alertId);
        exit;
    }
}
