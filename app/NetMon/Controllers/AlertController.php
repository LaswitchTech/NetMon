<?php

namespace App\NetMon\Controllers;

use App\Core\Controller;
use App\Models\AlertRepository;

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
}
