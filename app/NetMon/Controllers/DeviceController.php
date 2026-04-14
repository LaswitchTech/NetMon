<?php

namespace App\NetMon\Controllers;

use App\Core\Controller;
use App\Models\DeviceCheckRepository;
use App\Models\DeviceRepository;
use App\Models\ServiceCheckRepository;

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

        $user          = $principal['user'];
        $permissions   = $principal['permissions'];
        $appName       = $config['name'];
        $pageTitle     = 'Devices';
        $activeSection = 'Devices';

        $displayName = ($user['display_name'] ?? '') !== ''
            ? $user['display_name']
            : $user['username'];

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

    /**
     * GET /devices/{id}
     *
     * Renders the read-only Device Detail page.
     * Returns 404 if the device does not exist or has been soft-deleted.
     */
    public function show(array $params = []): void
    {
        $id         = (int) ($params['id'] ?? 0);
        $deviceRepo = new DeviceRepository($this->container->get('db'));
        $device     = $deviceRepo->findById($id);

        if ($device === null) {
            http_response_code(404);
            echo 'Device not found.';
            return;
        }

        $interfaces = $deviceRepo->findInterfacesWithAddresses($id);

        $checkRepo    = new DeviceCheckRepository($this->container->get('db'));
        $recentChecks  = $checkRepo->findRecentByDevice($id, 50);
        $historySeries = $checkRepo->findHistoryByDevice($id, 100);

        $serviceRepo = new ServiceCheckRepository($this->container->get('db'));
        $services    = $serviceRepo->findByDevice($id);

        // Load graph-ready history for each service (keyed by service id).
        // One query per service; typical devices have 2–5 services so this is acceptable.
        $serviceHistories = [];
        foreach ($services as $svc) {
            $svcId = (int) $svc['id'];
            $serviceHistories[$svcId] = $serviceRepo->findHistoryByService($svcId, 100);
        }

        [$user, $permissions, $appName, $displayName] = $this->principal();

        $pageTitle     = htmlspecialchars($device['name']);
        $activeSection = 'Devices';

        $viewsPath = __DIR__ . '/../../Views';

        ob_start();
        require $viewsPath . '/devices/show.php';
        $content = ob_get_clean();

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        require $viewsPath . '/layouts/app.php';
    }

    /**
     * GET /devices/create
     *
     * Renders the Add Device form.
     */
    public function createForm(array $params = []): void
    {
        [$user, $permissions, $appName, $displayName] = $this->principal();

        $pageTitle     = 'Add Device';
        $activeSection = 'Devices';
        $errors        = [];
        $old           = [];

        $viewsPath = __DIR__ . '/../../Views';

        ob_start();
        require $viewsPath . '/devices/create.php';
        $content = ob_get_clean();

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        require $viewsPath . '/layouts/app.php';
    }

    /**
     * POST /devices
     *
     * Validates input and creates a new device with a default management
     * interface and primary address. Redirects to /devices on success.
     */
    public function store(array $params = []): void
    {
        [$user, $permissions, $appName, $displayName] = $this->principal();

        $pageTitle     = 'Add Device';
        $activeSection = 'Devices';

        $name        = trim($_POST['name'] ?? '');
        $address     = trim($_POST['address'] ?? '');
        $description = trim($_POST['description'] ?? '') ?: null;

        $errors = $this->validateDevice($name, $address);

        if (!empty($errors)) {
            $old       = compact('name', 'address', 'description');
            $viewsPath = __DIR__ . '/../../Views';

            ob_start();
            require $viewsPath . '/devices/create.php';
            $content = ob_get_clean();

            http_response_code(422);
            header('Content-Type: text/html; charset=utf-8');
            require $viewsPath . '/layouts/app.php';
            return;
        }

        $repo = new DeviceRepository($this->container->get('db'));
        $repo->create(compact('name', 'address', 'description'));

        header('Location: /devices');
        exit;
    }

    /**
     * GET /devices/{id}/edit
     *
     * Renders the Edit Device form pre-populated with the device's current values.
     * Returns 404 if the device does not exist or has been soft-deleted.
     */
    public function editForm(array $params = []): void
    {
        $id   = (int) ($params['id'] ?? 0);
        $repo = new DeviceRepository($this->container->get('db'));

        $device = $repo->findById($id);

        if ($device === null) {
            http_response_code(404);
            echo 'Device not found.';
            return;
        }

        [$user, $permissions, $appName, $displayName] = $this->principal();

        $pageTitle     = 'Edit Device';
        $activeSection = 'Devices';
        $errors        = [];

        $viewsPath = __DIR__ . '/../../Views';

        ob_start();
        require $viewsPath . '/devices/edit.php';
        $content = ob_get_clean();

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        require $viewsPath . '/layouts/app.php';
    }

    /**
     * POST /devices/{id}
     *
     * Validates input and updates the device's name and primary address.
     * Redirects to /devices on success; re-renders the form with errors on failure.
     * Returns 404 if the device does not exist or has been soft-deleted.
     */
    public function update(array $params = []): void
    {
        $id   = (int) ($params['id'] ?? 0);
        $repo = new DeviceRepository($this->container->get('db'));

        $device = $repo->findById($id);

        if ($device === null) {
            http_response_code(404);
            echo 'Device not found.';
            return;
        }

        [$user, $permissions, $appName, $displayName] = $this->principal();

        $pageTitle     = 'Edit Device';
        $activeSection = 'Devices';

        $name        = trim($_POST['name'] ?? '');
        $address     = trim($_POST['address'] ?? '');
        $description = trim($_POST['description'] ?? '') ?: null;

        $errors = $this->validateDevice($name, $address);

        if (!empty($errors)) {
            // Re-populate device with submitted values so the form reflects what was entered.
            $device['name']        = $name;
            $device['address']     = $address;
            $device['description'] = $description;

            $viewsPath = __DIR__ . '/../../Views';

            ob_start();
            require $viewsPath . '/devices/edit.php';
            $content = ob_get_clean();

            http_response_code(422);
            header('Content-Type: text/html; charset=utf-8');
            require $viewsPath . '/layouts/app.php';
            return;
        }

        $repo->update($id, compact('name', 'address', 'description'));

        header('Location: /devices');
        exit;
    }

    /**
     * POST /devices/{id}/delete
     *
     * Soft-deletes the device (sets deleted_at). Does not hard-delete the row
     * or its interface/address records. Silently redirects even if the device
     * was already deleted or not found (idempotent from the browser's perspective).
     */
    public function delete(array $params = []): void
    {
        $id   = (int) ($params['id'] ?? 0);
        $repo = new DeviceRepository($this->container->get('db'));

        $device = $repo->findById($id);

        if ($device !== null) {
            $repo->softDelete($id);
        }

        header('Location: /devices');
        exit;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Extract common principal variables from the container.
     * Returns [$user, $permissions, $appName, $displayName].
     */
    private function principal(): array
    {
        $principal   = $this->container->get('principal');
        $config      = $this->container->get('config');
        $user        = $principal['user'];
        $permissions = $principal['permissions'];
        $appName     = $config['name'];
        $displayName = ($user['display_name'] ?? '') !== ''
            ? $user['display_name']
            : $user['username'];

        return [$user, $permissions, $appName, $displayName];
    }

    /**
     * Validate device form input.
     *
     * @return array<string, string>  Errors keyed by field name; empty on success.
     */
    private function validateDevice(string $name, string $address): array
    {
        $errors = [];

        if ($name === '') {
            $errors['name'] = 'Device name is required.';
        } elseif (mb_strlen($name) > 128) {
            $errors['name'] = 'Device name must be 128 characters or fewer.';
        }

        if ($address === '') {
            $errors['address'] = 'Host / IP address is required.';
        } elseif (!$this->isValidAddress($address)) {
            $errors['address'] = 'Please enter a valid IP address or hostname.';
        }

        return $errors;
    }

    /**
     * Returns true if the value is a valid IPv4 address, IPv6 address, or hostname.
     */
    private function isValidAddress(string $value): bool
    {
        if (filter_var($value, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        // Hostname / FQDN: labels of 1–63 alphanumeric/hyphen chars, separated by dots.
        // Allows single-label hostnames (e.g. "router") as well as FQDNs.
        return (bool) preg_match(
            '/^(?:[a-zA-Z0-9](?:[a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)*[a-zA-Z0-9](?:[a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?$/',
            $value
        );
    }
}
