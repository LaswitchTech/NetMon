<?php

namespace App\NetMon\Controllers;

use App\Core\Controller;
use App\Models\DeviceRepository;

class DeviceController extends Controller
{
    /**
     * GET /devices
     *
     * Renders the Devices list page inside the authenticated app shell.
     * Authentication is enforced by the WebAuth middleware applied in routes/web.php.
     */
    public function index(array $params = []): void
    {
        $principal   = $this->container->get('principal');
        $config      = $this->container->get('config');

        $user        = $principal['user'];
        $permissions = $principal['permissions'];
        $appName     = $config['name'];
        $pageTitle   = 'Devices';

        $displayName = ($user['display_name'] ?? '') !== ''
            ? $user['display_name']
            : $user['username'];

        // Fetch device list
        $deviceRepo = new DeviceRepository($this->container->get('db'));
        $devices    = $deviceRepo->findAll();

        $viewsPath = __DIR__ . '/../../Views';

        ob_start();
        require $viewsPath . '/devices/index.php';
        $content = ob_get_clean();

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        require $viewsPath . '/layouts/app.php';
    }
}
