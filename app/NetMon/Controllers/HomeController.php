<?php

namespace App\NetMon\Controllers;

use App\Core\Controller;
use App\Models\AlertRepository;
use App\Models\DeviceCheckRepository;
use App\Models\DeviceRepository;
use App\Models\ServiceCheckRepository;

class HomeController extends Controller
{
    /**
     * GET /
     *
     * Renders the main monitoring dashboard.
     * Authentication is enforced by the WebAuth middleware applied in routes/web.php.
     *
     * Data loaded:
     *   - Summary counts: total devices, online, offline, open alerts, services down
     *   - Recent alerts (last 10, any status)
     *   - Recent device checks (last 10, across all devices)
     */
    public function index(array $params = []): void
    {
        $principal = $this->container->get('principal');
        $config    = $this->container->get('config');

        $user        = $principal['user'];
        $permissions = $principal['permissions'];
        $appName     = $config['name'];
        $pageTitle   = 'Dashboard';

        $displayName = ($user['display_name'] ?? '') !== ''
            ? $user['display_name']
            : $user['username'];

        // ----------------------------------------------------------------
        // Data
        // ----------------------------------------------------------------
        $db = $this->container->get('db');

        $deviceRepo  = new DeviceRepository($db);
        $alertRepo   = new AlertRepository($db);
        $checkRepo   = new DeviceCheckRepository($db);
        $serviceRepo = new ServiceCheckRepository($db);

        // Summary counts — one scalar query each
        $totalDevices   = $deviceRepo->countAll();
        $devicesOnline  = $deviceRepo->countByStatus('online');
        $devicesOffline = $deviceRepo->countByStatus('offline');
        $openAlerts     = $alertRepo->countOpen();
        $servicesDown   = $serviceRepo->countServicesDown();

        // Recent activity feeds — limited result sets
        $recentAlerts = $alertRepo->findRecent(10);
        $recentChecks = $checkRepo->findRecentChecks(10);

        // ----------------------------------------------------------------
        // Render
        // ----------------------------------------------------------------
        $viewsPath = __DIR__ . '/../../Views';

        ob_start();
        require $viewsPath . '/dashboard/index.php';
        $content = ob_get_clean();

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        require $viewsPath . '/layouts/app.php';
    }
}
