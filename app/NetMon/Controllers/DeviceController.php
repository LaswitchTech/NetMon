<?php

namespace App\NetMon\Controllers;

use App\Core\Controller;
use App\Models\AlertRepository;
use App\Models\DeviceCheckRepository;
use App\Models\DeviceRepository;
use App\Models\ServiceCheckRepository;
use App\Modules\Notes\Models\NoteRepository;
use App\Modules\Notes\Services\NoteService;

// Supported protocols for monitored services (Phase 8 — TCP only).
// Extend this list when HTTP/HTTPS/UDP checkers are added.
define('NETMON_SERVICE_PROTOCOLS', ['tcp']);

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

        $interfaces         = $deviceRepo->findInterfacesWithAddresses($id);
        $possibleDuplicates = $deviceRepo->possibleDuplicates($id);

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

        // Open alerts for this device — one query, filtered to 'open' in PHP to avoid
        // a separate repository method for this read path.
        $alertRepo  = new AlertRepository($this->container->get('db'));
        $openAlerts = array_values(array_filter(
            $alertRepo->findByDevice($id),
            fn($a) => $a['status'] === 'open'
        ));

        // Notes — polymorphic; entity_type = 'device'.
        $noteRepo = new NoteRepository($this->container->get('db'));
        $notes    = $noteRepo->findByEntity('device', $id);

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
     * GET /devices/{id}/merge
     *
     * Renders the Merge Device form. Lists all other active devices as
     * potential merge targets. Returns 404 if the source device is not found.
     */
    public function mergeForm(array $params = []): void
    {
        $id   = (int) ($params['id'] ?? 0);
        $repo = new DeviceRepository($this->container->get('db'));

        $device = $repo->findById($id);
        if ($device === null) {
            http_response_code(404);
            echo 'Device not found.';
            return;
        }

        // All active devices except the source are valid merge targets.
        $candidates = array_values(array_filter(
            $repo->findAllForSelect(),
            fn($d) => (int) $d['id'] !== $id
        ));

        [$user, $permissions, $appName, $displayName] = $this->principal();

        $pageTitle     = 'Merge Device';
        $activeSection = 'Devices';
        $errors        = [];

        $viewsPath = __DIR__ . '/../../Views';

        ob_start();
        require $viewsPath . '/devices/merge.php';
        $content = ob_get_clean();

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        require $viewsPath . '/layouts/app.php';
    }

    /**
     * POST /devices/{id}/merge
     *
     * Validates the selected target device and executes the merge via
     * DeviceRepository::mergeInto(). On success, redirects to the target
     * device page with a ?merged= query parameter so the detail page can
     * display a one-time confirmation banner.
     *
     * Re-renders the merge form at HTTP 422 on any validation error.
     */
    public function merge(array $params = []): void
    {
        $sourceId = (int) ($params['id'] ?? 0);
        $repo     = new DeviceRepository($this->container->get('db'));

        $device = $repo->findById($sourceId);
        if ($device === null) {
            http_response_code(404);
            echo 'Device not found.';
            return;
        }

        $targetId = (int) ($_POST['target_device_id'] ?? 0);

        [$user, $permissions, $appName, $displayName] = $this->principal();
        $pageTitle     = 'Merge Device';
        $activeSection = 'Devices';

        // Validate selection.
        $errors = [];

        if ($targetId === 0) {
            $errors['target_device_id'] = 'Please select a target device.';
        } elseif ($targetId === $sourceId) {
            $errors['target_device_id'] = 'Cannot merge a device into itself.';
        } else {
            $target = $repo->findById($targetId);
            if ($target === null) {
                $errors['target_device_id'] = 'Selected target device was not found or has been deleted.';
            }
        }

        if (!empty($errors)) {
            $candidates = array_values(array_filter(
                $repo->findAllForSelect(),
                fn($d) => (int) $d['id'] !== $sourceId
            ));

            $viewsPath = __DIR__ . '/../../Views';

            ob_start();
            require $viewsPath . '/devices/merge.php';
            $content = ob_get_clean();

            http_response_code(422);
            header('Content-Type: text/html; charset=utf-8');
            require $viewsPath . '/layouts/app.php';
            return;
        }

        $repo->mergeInto($sourceId, $targetId);

        // Redirect to target device with a flash hint so the page can show a banner.
        $encodedName = urlencode($device['name']);
        header("Location: /devices/{$targetId}?merged=" . $encodedName);
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
    // Notes
    // -------------------------------------------------------------------------

    /**
     * POST /devices/{id}/notes
     *
     * Validates and creates a new note on the device.
     * Associates the authenticated user as the author.
     * Redirects to /devices/{id} on success or failure (flash via ?note_error=).
     */
    public function addNote(array $params = []): void
    {
        $deviceId   = (int) ($params['id'] ?? 0);
        $deviceRepo = new DeviceRepository($this->container->get('db'));
        $device     = $deviceRepo->findById($deviceId);

        if ($device === null) {
            http_response_code(404);
            echo 'Device not found.';
            return;
        }

        [$user, $permissions, $appName, $displayName] = $this->principal();
        $content = $_POST['content'] ?? '';

        $noteRepo = new NoteRepository($this->container->get('db'));
        $service  = new NoteService($noteRepo);

        try {
            $service->addNote('device', $deviceId, (int) $user['id'], $content);
        } catch (\InvalidArgumentException $e) {
            header('Location: /devices/' . $deviceId . '?note_error=' . urlencode($e->getMessage()) . '#notes');
            exit;
        }

        header('Location: /devices/' . $deviceId . '#notes');
        exit;
    }

    /**
     * POST /devices/{id}/notes/{noteId}/delete
     *
     * Deletes a note if the requesting user is the note's author.
     * Redirects to /devices/{id} on success.
     * Redirects with ?note_error= flash on authorization failure.
     */
    public function deleteNote(array $params = []): void
    {
        $deviceId = (int) ($params['id'] ?? 0);
        $noteId   = (int) ($params['noteId'] ?? 0);

        $deviceRepo = new DeviceRepository($this->container->get('db'));
        $device     = $deviceRepo->findById($deviceId);

        if ($device === null) {
            http_response_code(404);
            echo 'Device not found.';
            return;
        }

        [$user, $permissions, $appName, $displayName] = $this->principal();

        $noteRepo = new NoteRepository($this->container->get('db'));
        $service  = new NoteService($noteRepo);

        try {
            $service->removeNote($noteId, (int) $user['id']);
        } catch (\InvalidArgumentException $e) {
            header('Location: /devices/' . $deviceId . '?note_error=' . urlencode($e->getMessage()) . '#notes');
            exit;
        }

        header('Location: /devices/' . $deviceId . '#notes');
        exit;
    }

    // -------------------------------------------------------------------------
    // Interface CRUD
    // -------------------------------------------------------------------------

    /**
     * GET /devices/{id}/interfaces/create
     *
     * Renders the Add Interface form for a device.
     */
    public function interfaceCreateForm(array $params = []): void
    {
        $deviceId   = (int) ($params['id'] ?? 0);
        $deviceRepo = new DeviceRepository($this->container->get('db'));
        $device     = $deviceRepo->findById($deviceId);

        if ($device === null) {
            http_response_code(404);
            echo 'Device not found.';
            return;
        }

        [$user, $permissions, $appName, $displayName] = $this->principal();
        $pageTitle     = 'Add Interface';
        $activeSection = 'Devices';
        $errors        = [];
        $old           = [];

        $viewsPath = __DIR__ . '/../../Views';
        ob_start();
        require $viewsPath . '/devices/interface_create.php';
        $content = ob_get_clean();

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        require $viewsPath . '/layouts/app.php';
    }

    /**
     * POST /devices/{id}/interfaces
     *
     * Validates and creates a new interface on the device.
     * Redirects to /devices/{id} on success.
     */
    public function interfaceStore(array $params = []): void
    {
        $deviceId   = (int) ($params['id'] ?? 0);
        $deviceRepo = new DeviceRepository($this->container->get('db'));
        $device     = $deviceRepo->findById($deviceId);

        if ($device === null) {
            http_response_code(404);
            echo 'Device not found.';
            return;
        }

        [$user, $permissions, $appName, $displayName] = $this->principal();
        $pageTitle     = 'Add Interface';
        $activeSection = 'Devices';

        $name         = trim($_POST['name'] ?? '');
        $mac          = trim($_POST['mac_address'] ?? '') ?: null;
        $isManagement = isset($_POST['is_management']) ? 1 : 0;
        $description  = trim($_POST['description'] ?? '') ?: null;

        $errors = $this->validateInterface($name, $mac);

        if (empty($errors)) {
            try {
                $deviceRepo->createInterface($deviceId, [
                    'name'          => $name,
                    'mac_address'   => $mac,
                    'is_management' => $isManagement,
                    'description'   => $description,
                ]);
            } catch (\RuntimeException $e) {
                $errors['is_management'] = $e->getMessage();
            }
        }

        if (!empty($errors)) {
            $old       = compact('name', 'mac', 'isManagement', 'description');
            $viewsPath = __DIR__ . '/../../Views';
            ob_start();
            require $viewsPath . '/devices/interface_create.php';
            $content = ob_get_clean();

            http_response_code(422);
            header('Content-Type: text/html; charset=utf-8');
            require $viewsPath . '/layouts/app.php';
            return;
        }

        header("Location: /devices/{$deviceId}");
        exit;
    }

    /**
     * GET /devices/interfaces/{id}/edit
     *
     * Renders the Edit Interface form.
     */
    public function interfaceEditForm(array $params = []): void
    {
        $interfaceId = (int) ($params['id'] ?? 0);
        $deviceRepo  = new DeviceRepository($this->container->get('db'));
        $iface       = $deviceRepo->findInterfaceById($interfaceId);

        if ($iface === null) {
            http_response_code(404);
            echo 'Interface not found.';
            return;
        }

        $device = $deviceRepo->findById((int) $iface['device_id']);
        if ($device === null) {
            http_response_code(404);
            echo 'Device not found.';
            return;
        }

        [$user, $permissions, $appName, $displayName] = $this->principal();
        $pageTitle     = 'Edit Interface';
        $activeSection = 'Devices';
        $errors        = [];

        $viewsPath = __DIR__ . '/../../Views';
        ob_start();
        require $viewsPath . '/devices/interface_edit.php';
        $content = ob_get_clean();

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        require $viewsPath . '/layouts/app.php';
    }

    /**
     * POST /devices/interfaces/{id}
     *
     * Validates and updates an existing interface.
     * Redirects to /devices/{device_id} on success.
     */
    public function interfaceUpdate(array $params = []): void
    {
        $interfaceId = (int) ($params['id'] ?? 0);
        $deviceRepo  = new DeviceRepository($this->container->get('db'));
        $iface       = $deviceRepo->findInterfaceById($interfaceId);

        if ($iface === null) {
            http_response_code(404);
            echo 'Interface not found.';
            return;
        }

        $deviceId = (int) $iface['device_id'];
        $device   = $deviceRepo->findById($deviceId);
        if ($device === null) {
            http_response_code(404);
            echo 'Device not found.';
            return;
        }

        [$user, $permissions, $appName, $displayName] = $this->principal();
        $pageTitle     = 'Edit Interface';
        $activeSection = 'Devices';

        $name         = trim($_POST['name'] ?? '');
        $mac          = trim($_POST['mac_address'] ?? '') ?: null;
        $isManagement = isset($_POST['is_management']) ? 1 : 0;
        $description  = trim($_POST['description'] ?? '') ?: null;

        $errors = $this->validateInterface($name, $mac);

        if (empty($errors)) {
            try {
                $deviceRepo->updateInterface($interfaceId, [
                    'name'          => $name,
                    'mac_address'   => $mac,
                    'is_management' => $isManagement,
                    'description'   => $description,
                ]);
            } catch (\RuntimeException $e) {
                $errors['is_management'] = $e->getMessage();
            }
        }

        if (!empty($errors)) {
            // Re-populate $iface with submitted values.
            $iface['name']          = $name;
            $iface['mac_address']   = $mac;
            $iface['is_management'] = $isManagement;
            $iface['description']   = $description;

            $viewsPath = __DIR__ . '/../../Views';
            ob_start();
            require $viewsPath . '/devices/interface_edit.php';
            $content = ob_get_clean();

            http_response_code(422);
            header('Content-Type: text/html; charset=utf-8');
            require $viewsPath . '/layouts/app.php';
            return;
        }

        header("Location: /devices/{$deviceId}");
        exit;
    }

    /**
     * POST /devices/interfaces/{id}/delete
     *
     * Deletes a non-management interface that has no addresses.
     * Redirects to /devices/{device_id}. On integrity failure, redirects back
     * with ?error= flash.
     */
    public function interfaceDelete(array $params = []): void
    {
        $interfaceId = (int) ($params['id'] ?? 0);
        $deviceRepo  = new DeviceRepository($this->container->get('db'));
        $iface       = $deviceRepo->findInterfaceById($interfaceId);

        if ($iface === null) {
            header('Location: /devices');
            exit;
        }

        $deviceId = (int) $iface['device_id'];

        try {
            $deviceRepo->deleteInterface($interfaceId);
        } catch (\RuntimeException $e) {
            header('Location: /devices/' . $deviceId . '?error=' . urlencode($e->getMessage()));
            exit;
        }

        header("Location: /devices/{$deviceId}");
        exit;
    }

    // -------------------------------------------------------------------------
    // Address CRUD
    // -------------------------------------------------------------------------

    /**
     * GET /devices/interfaces/{id}/addresses/create
     *
     * Renders the Add Address form for an interface.
     */
    public function addressCreateForm(array $params = []): void
    {
        $interfaceId = (int) ($params['id'] ?? 0);
        $deviceRepo  = new DeviceRepository($this->container->get('db'));
        $iface       = $deviceRepo->findInterfaceById($interfaceId);

        if ($iface === null) {
            http_response_code(404);
            echo 'Interface not found.';
            return;
        }

        $device = $deviceRepo->findById((int) $iface['device_id']);
        if ($device === null) {
            http_response_code(404);
            echo 'Device not found.';
            return;
        }

        [$user, $permissions, $appName, $displayName] = $this->principal();
        $pageTitle     = 'Add Address';
        $activeSection = 'Devices';
        $errors        = [];
        $old           = [];

        $viewsPath = __DIR__ . '/../../Views';
        ob_start();
        require $viewsPath . '/devices/address_create.php';
        $content = ob_get_clean();

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        require $viewsPath . '/layouts/app.php';
    }

    /**
     * POST /devices/interfaces/{id}/addresses
     *
     * Validates and creates a new address on an interface.
     * Redirects to /devices/{device_id} on success.
     */
    public function addressStore(array $params = []): void
    {
        $interfaceId = (int) ($params['id'] ?? 0);
        $deviceRepo  = new DeviceRepository($this->container->get('db'));
        $iface       = $deviceRepo->findInterfaceById($interfaceId);

        if ($iface === null) {
            http_response_code(404);
            echo 'Interface not found.';
            return;
        }

        $deviceId = (int) $iface['device_id'];
        $device   = $deviceRepo->findById($deviceId);
        if ($device === null) {
            http_response_code(404);
            echo 'Device not found.';
            return;
        }

        [$user, $permissions, $appName, $displayName] = $this->principal();
        $pageTitle     = 'Add Address';
        $activeSection = 'Devices';

        $address   = trim($_POST['address'] ?? '');
        $isPrimary = isset($_POST['is_primary']) ? 1 : 0;

        $errors = $this->validateAddress($address);

        if (empty($errors)) {
            $deviceRepo->createAddress($interfaceId, [
                'address'    => $address,
                'is_primary' => $isPrimary,
            ]);
        }

        if (!empty($errors)) {
            $old       = compact('address', 'isPrimary');
            $viewsPath = __DIR__ . '/../../Views';
            ob_start();
            require $viewsPath . '/devices/address_create.php';
            $content = ob_get_clean();

            http_response_code(422);
            header('Content-Type: text/html; charset=utf-8');
            require $viewsPath . '/layouts/app.php';
            return;
        }

        header("Location: /devices/{$deviceId}");
        exit;
    }

    /**
     * GET /devices/addresses/{id}/edit
     *
     * Renders the Edit Address form.
     */
    public function addressEditForm(array $params = []): void
    {
        $addressId  = (int) ($params['id'] ?? 0);
        $deviceRepo = new DeviceRepository($this->container->get('db'));
        $addr       = $deviceRepo->findAddressById($addressId);

        if ($addr === null) {
            http_response_code(404);
            echo 'Address not found.';
            return;
        }

        $iface  = $deviceRepo->findInterfaceById((int) $addr['interface_id']);
        $device = $deviceRepo->findById((int) $iface['device_id']);

        if ($device === null) {
            http_response_code(404);
            echo 'Device not found.';
            return;
        }

        [$user, $permissions, $appName, $displayName] = $this->principal();
        $pageTitle     = 'Edit Address';
        $activeSection = 'Devices';
        $errors        = [];

        $viewsPath = __DIR__ . '/../../Views';
        ob_start();
        require $viewsPath . '/devices/address_edit.php';
        $content = ob_get_clean();

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        require $viewsPath . '/layouts/app.php';
    }

    /**
     * POST /devices/addresses/{id}
     *
     * Validates and updates an existing address.
     * Redirects to /devices/{device_id} on success.
     */
    public function addressUpdate(array $params = []): void
    {
        $addressId  = (int) ($params['id'] ?? 0);
        $deviceRepo = new DeviceRepository($this->container->get('db'));
        $addr       = $deviceRepo->findAddressById($addressId);

        if ($addr === null) {
            http_response_code(404);
            echo 'Address not found.';
            return;
        }

        $iface    = $deviceRepo->findInterfaceById((int) $addr['interface_id']);
        $deviceId = (int) $iface['device_id'];
        $device   = $deviceRepo->findById($deviceId);

        if ($device === null) {
            http_response_code(404);
            echo 'Device not found.';
            return;
        }

        [$user, $permissions, $appName, $displayName] = $this->principal();
        $pageTitle     = 'Edit Address';
        $activeSection = 'Devices';

        $address   = trim($_POST['address'] ?? '');
        $isPrimary = isset($_POST['is_primary']) ? 1 : 0;

        $errors = $this->validateAddress($address);

        if (empty($errors)) {
            $deviceRepo->updateAddress($addressId, ['address' => $address, 'is_primary' => $isPrimary]);
        }

        if (!empty($errors)) {
            $addr['address']    = $address;
            $addr['is_primary'] = $isPrimary;

            $viewsPath = __DIR__ . '/../../Views';
            ob_start();
            require $viewsPath . '/devices/address_edit.php';
            $content = ob_get_clean();

            http_response_code(422);
            header('Content-Type: text/html; charset=utf-8');
            require $viewsPath . '/layouts/app.php';
            return;
        }

        header("Location: /devices/{$deviceId}");
        exit;
    }

    /**
     * POST /devices/addresses/{id}/delete
     *
     * Deletes a non-primary address (or a primary only when alternatives exist).
     * Redirects to /devices/{device_id}. On integrity failure, redirects with ?error= flash.
     */
    public function addressDelete(array $params = []): void
    {
        $addressId  = (int) ($params['id'] ?? 0);
        $deviceRepo = new DeviceRepository($this->container->get('db'));
        $addr       = $deviceRepo->findAddressById($addressId);

        if ($addr === null) {
            header('Location: /devices');
            exit;
        }

        $iface    = $deviceRepo->findInterfaceById((int) $addr['interface_id']);
        $deviceId = (int) $iface['device_id'];

        try {
            $deviceRepo->deleteAddress($addressId);
        } catch (\RuntimeException $e) {
            header('Location: /devices/' . $deviceId . '?error=' . urlencode($e->getMessage()));
            exit;
        }

        header("Location: /devices/{$deviceId}");
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

    // -------------------------------------------------------------------------
    // Monitored Service CRUD
    // -------------------------------------------------------------------------

    /**
     * GET /devices/{id}/services/create
     *
     * Renders the Add Monitored Service form for a device.
     */
    public function serviceCreateForm(array $params = []): void
    {
        $deviceId   = (int) ($params['id'] ?? 0);
        $deviceRepo = new DeviceRepository($this->container->get('db'));
        $device     = $deviceRepo->findById($deviceId);

        if ($device === null) {
            http_response_code(404);
            echo 'Device not found.';
            return;
        }

        [$user, $permissions, $appName, $displayName] = $this->principal();
        $pageTitle     = 'Add Monitored Service';
        $activeSection = 'Devices';
        $errors        = [];
        $old           = [];
        $protocols     = NETMON_SERVICE_PROTOCOLS;

        $viewsPath = __DIR__ . '/../../Views';
        ob_start();
        require $viewsPath . '/devices/service_create.php';
        $content = ob_get_clean();

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        require $viewsPath . '/layouts/app.php';
    }

    /**
     * POST /devices/{id}/services
     *
     * Validates and creates a new monitored service on the device.
     * Redirects to /devices/{id} on success.
     */
    public function serviceStore(array $params = []): void
    {
        $deviceId   = (int) ($params['id'] ?? 0);
        $deviceRepo = new DeviceRepository($this->container->get('db'));
        $device     = $deviceRepo->findById($deviceId);

        if ($device === null) {
            http_response_code(404);
            echo 'Device not found.';
            return;
        }

        [$user, $permissions, $appName, $displayName] = $this->principal();
        $pageTitle     = 'Add Monitored Service';
        $activeSection = 'Devices';
        $protocols     = NETMON_SERVICE_PROTOCOLS;

        $name             = trim($_POST['name'] ?? '');
        $protocol         = trim($_POST['protocol'] ?? '');
        $port             = trim($_POST['port'] ?? '');
        $monitoringEnabled = isset($_POST['monitoring_enabled']) ? 1 : 0;

        $errors = $this->validateService($name, $protocol, $port);

        if (empty($errors)) {
            $svcRepo = new ServiceCheckRepository($this->container->get('db'));
            $svcRepo->createService($deviceId, [
                'name'              => $name,
                'protocol'          => $protocol,
                'port'              => (int) $port,
                'monitoring_enabled' => $monitoringEnabled,
            ]);

            header("Location: /devices/{$deviceId}");
            exit;
        }

        $old       = compact('name', 'protocol', 'port', 'monitoringEnabled');
        $viewsPath = __DIR__ . '/../../Views';
        ob_start();
        require $viewsPath . '/devices/service_create.php';
        $content = ob_get_clean();

        http_response_code(422);
        header('Content-Type: text/html; charset=utf-8');
        require $viewsPath . '/layouts/app.php';
    }

    /**
     * GET /devices/services/{id}/edit
     *
     * Renders the Edit Monitored Service form.
     */
    public function serviceEditForm(array $params = []): void
    {
        $serviceId = (int) ($params['id'] ?? 0);
        $svcRepo   = new ServiceCheckRepository($this->container->get('db'));
        $service   = $svcRepo->findServiceById($serviceId);

        if ($service === null) {
            http_response_code(404);
            echo 'Service not found.';
            return;
        }

        $deviceRepo = new DeviceRepository($this->container->get('db'));
        $device     = $deviceRepo->findById((int) $service['device_id']);

        if ($device === null) {
            http_response_code(404);
            echo 'Device not found.';
            return;
        }

        [$user, $permissions, $appName, $displayName] = $this->principal();
        $pageTitle     = 'Edit Monitored Service';
        $activeSection = 'Devices';
        $errors        = [];
        $protocols     = NETMON_SERVICE_PROTOCOLS;

        $viewsPath = __DIR__ . '/../../Views';
        ob_start();
        require $viewsPath . '/devices/service_edit.php';
        $content = ob_get_clean();

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        require $viewsPath . '/layouts/app.php';
    }

    /**
     * POST /devices/services/{id}
     *
     * Validates and updates an existing monitored service.
     * Redirects to /devices/{device_id} on success.
     */
    public function serviceUpdate(array $params = []): void
    {
        $serviceId = (int) ($params['id'] ?? 0);
        $svcRepo   = new ServiceCheckRepository($this->container->get('db'));
        $service   = $svcRepo->findServiceById($serviceId);

        if ($service === null) {
            http_response_code(404);
            echo 'Service not found.';
            return;
        }

        $deviceId   = (int) $service['device_id'];
        $deviceRepo = new DeviceRepository($this->container->get('db'));
        $device     = $deviceRepo->findById($deviceId);

        if ($device === null) {
            http_response_code(404);
            echo 'Device not found.';
            return;
        }

        [$user, $permissions, $appName, $displayName] = $this->principal();
        $pageTitle     = 'Edit Monitored Service';
        $activeSection = 'Devices';
        $protocols     = NETMON_SERVICE_PROTOCOLS;

        $name              = trim($_POST['name'] ?? '');
        $protocol          = trim($_POST['protocol'] ?? '');
        $port              = trim($_POST['port'] ?? '');
        $monitoringEnabled = isset($_POST['monitoring_enabled']) ? 1 : 0;

        $errors = $this->validateService($name, $protocol, $port);

        if (empty($errors)) {
            $svcRepo->updateService($serviceId, [
                'name'               => $name,
                'protocol'           => $protocol,
                'port'               => (int) $port,
                'monitoring_enabled' => $monitoringEnabled,
            ]);

            header("Location: /devices/{$deviceId}");
            exit;
        }

        // Re-populate $service with submitted values for form re-display.
        $service['name']               = $name;
        $service['protocol']           = $protocol;
        $service['port']               = $port;
        $service['monitoring_enabled'] = $monitoringEnabled;

        $viewsPath = __DIR__ . '/../../Views';
        ob_start();
        require $viewsPath . '/devices/service_edit.php';
        $content = ob_get_clean();

        http_response_code(422);
        header('Content-Type: text/html; charset=utf-8');
        require $viewsPath . '/layouts/app.php';
    }

    /**
     * POST /devices/services/{id}/delete
     *
     * Permanently deletes a monitored service and all its check history
     * (cascades via service_checks FK). Redirects to /devices/{device_id}.
     */
    public function serviceDelete(array $params = []): void
    {
        $serviceId = (int) ($params['id'] ?? 0);
        $svcRepo   = new ServiceCheckRepository($this->container->get('db'));
        $service   = $svcRepo->findServiceById($serviceId);

        if ($service === null) {
            header('Location: /devices');
            exit;
        }

        $deviceId = (int) $service['device_id'];
        $svcRepo->deleteService($serviceId);

        header("Location: /devices/{$deviceId}");
        exit;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Validate interface form input.
     *
     * @return array<string, string>
     */
    private function validateInterface(string $name, ?string $mac): array
    {
        $errors = [];

        if ($name === '') {
            $errors['name'] = 'Interface name is required.';
        } elseif (mb_strlen($name) > 64) {
            $errors['name'] = 'Interface name must be 64 characters or fewer.';
        }

        if ($mac !== null && $mac !== '') {
            // Accept common MAC formats: 00:11:22:33:44:55 or 00-11-22-33-44-55 or 001122334455
            if (!preg_match('/^([0-9A-Fa-f]{2}[:\-]){5}[0-9A-Fa-f]{2}$|^[0-9A-Fa-f]{12}$/', $mac)) {
                $errors['mac_address'] = 'Enter a valid MAC address (e.g. 00:11:22:33:44:55).';
            }
        }

        return $errors;
    }

    /**
     * Validate address form input.
     *
     * @return array<string, string>
     */
    private function validateAddress(string $address): array
    {
        $errors = [];

        if ($address === '') {
            $errors['address'] = 'Address is required.';
        } elseif (!$this->isValidAddress($address)) {
            $errors['address'] = 'Please enter a valid IP address or hostname.';
        }

        return $errors;
    }

    /**
     * Validate monitored service form input.
     *
     * @return array<string, string>
     */
    private function validateService(string $name, string $protocol, string $port): array
    {
        $errors = [];

        if ($name === '') {
            $errors['name'] = 'Service name is required.';
        } elseif (mb_strlen($name) > 128) {
            $errors['name'] = 'Service name must be 128 characters or fewer.';
        }

        if (!in_array($protocol, NETMON_SERVICE_PROTOCOLS, true)) {
            $errors['protocol'] = 'Select a valid protocol.';
        }

        if ($port === '') {
            $errors['port'] = 'Port number is required.';
        } elseif (!ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
            $errors['port'] = 'Port must be a number between 1 and 65535.';
        }

        return $errors;
    }
}
