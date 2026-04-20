<?php

namespace App\NetMon\Controllers;

use App\Core\Controller;
use App\Models\DeviceRepository;
use App\Models\DiscoveryRepository;
use App\Modules\Notes\Models\NoteRepository;
use App\Modules\Notes\Services\NoteService;

class DiscoveryController extends Controller
{
    // -------------------------------------------------------------------------
    // List
    // -------------------------------------------------------------------------

    /**
     * GET /discovery
     *
     * Renders the discovery findings list page inside the authenticated app shell.
     * Authentication is enforced by the WebAuth middleware.
     */
    public function index(array $params = []): void
    {
        [$user, $permissions, $appName, $displayName] = $this->principal();

        $pageTitle     = 'Discovery';
        $activeSection = 'Discovery';

        $repo     = new DiscoveryRepository($this->container->get('db'));
        $findings = $repo->findAllFindings(100);
        $counts   = $repo->countByStatus();

        $viewsPath = __DIR__ . '/../../Views';

        ob_start();
        require $viewsPath . '/discovery/index.php';
        $content = ob_get_clean();

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        require $viewsPath . '/layouts/app.php';
    }

    // -------------------------------------------------------------------------
    // Jobs — CRUD
    // -------------------------------------------------------------------------

    /**
     * GET /discovery/jobs
     *
     * Lists all discovery jobs.
     */
    public function jobIndex(array $params = []): void
    {
        [$user, $permissions, $appName, $displayName] = $this->principal();

        $pageTitle     = 'Discovery Jobs';
        $activeSection = 'Discovery';

        $repo = new DiscoveryRepository($this->container->get('db'));
        $jobs = $repo->findAllJobs();

        $viewsPath = __DIR__ . '/../../Views';

        ob_start();
        require $viewsPath . '/discovery/jobs/index.php';
        $content = ob_get_clean();

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        require $viewsPath . '/layouts/app.php';
    }

    /**
     * GET /discovery/jobs/create
     *
     * Renders the create-job form.
     */
    public function jobCreateForm(array $params = []): void
    {
        [$user, $permissions, $appName, $displayName] = $this->principal();

        $pageTitle     = 'New Discovery Job';
        $activeSection = 'Discovery';

        $old    = ['name' => '', 'subnet' => '', 'enabled' => true];
        $errors = [];

        $viewsPath = __DIR__ . '/../../Views';

        ob_start();
        require $viewsPath . '/discovery/jobs/create.php';
        $content = ob_get_clean();

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        require $viewsPath . '/layouts/app.php';
    }

    /**
     * POST /discovery/jobs
     *
     * Validates and creates a new discovery job.
     * On success: redirects to /discovery/jobs.
     * On failure: re-renders the form with errors.
     */
    public function jobStore(array $params = []): void
    {
        [$user, $permissions, $appName, $displayName] = $this->principal();

        $name    = trim($_POST['name']   ?? '');
        $subnet  = trim($_POST['subnet'] ?? '');
        $enabled = isset($_POST['enabled']);

        $errors = $this->validateJob($name, $subnet);

        if (!empty($errors)) {
            $pageTitle     = 'New Discovery Job';
            $activeSection = 'Discovery';

            $old = compact('name', 'subnet', 'enabled');

            $viewsPath = __DIR__ . '/../../Views';

            ob_start();
            require $viewsPath . '/discovery/jobs/create.php';
            $content = ob_get_clean();

            http_response_code(422);
            header('Content-Type: text/html; charset=utf-8');
            require $viewsPath . '/layouts/app.php';
            return;
        }

        $repo = new DiscoveryRepository($this->container->get('db'));
        $repo->createJob(compact('name', 'subnet', 'enabled'));

        header('Location: /discovery/jobs');
        exit;
    }

    /**
     * GET /discovery/jobs/{id}/edit
     *
     * Renders the edit-job form pre-filled with the job's current data.
     */
    public function jobEditForm(array $params = []): void
    {
        $id   = (int) ($params['id'] ?? 0);
        $repo = new DiscoveryRepository($this->container->get('db'));
        $job  = $repo->findJobById($id);

        if ($job === null) {
            http_response_code(404);
            echo 'Job not found.';
            return;
        }

        [$user, $permissions, $appName, $displayName] = $this->principal();

        $pageTitle     = 'Edit Job — ' . htmlspecialchars($job['name']);
        $activeSection = 'Discovery';

        $old    = ['name' => $job['name'], 'subnet' => $job['subnet'], 'enabled' => (bool) $job['enabled']];
        $errors = [];

        $viewsPath = __DIR__ . '/../../Views';

        ob_start();
        require $viewsPath . '/discovery/jobs/edit.php';
        $content = ob_get_clean();

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        require $viewsPath . '/layouts/app.php';
    }

    /**
     * POST /discovery/jobs/{id}
     *
     * Validates and updates an existing discovery job.
     * On success: redirects to /discovery/jobs.
     * On failure: re-renders the edit form with errors.
     */
    public function jobUpdate(array $params = []): void
    {
        $id   = (int) ($params['id'] ?? 0);
        $repo = new DiscoveryRepository($this->container->get('db'));
        $job  = $repo->findJobById($id);

        if ($job === null) {
            http_response_code(404);
            echo 'Job not found.';
            return;
        }

        [$user, $permissions, $appName, $displayName] = $this->principal();

        $name    = trim($_POST['name']   ?? '');
        $subnet  = trim($_POST['subnet'] ?? '');
        $enabled = isset($_POST['enabled']);

        $errors = $this->validateJob($name, $subnet);

        if (!empty($errors)) {
            $pageTitle     = 'Edit Job — ' . htmlspecialchars($job['name']);
            $activeSection = 'Discovery';

            $old = compact('name', 'subnet', 'enabled');

            $viewsPath = __DIR__ . '/../../Views';

            ob_start();
            require $viewsPath . '/discovery/jobs/edit.php';
            $content = ob_get_clean();

            http_response_code(422);
            header('Content-Type: text/html; charset=utf-8');
            require $viewsPath . '/layouts/app.php';
            return;
        }

        $repo->updateJob($id, compact('name', 'subnet', 'enabled'));

        header('Location: /discovery/jobs');
        exit;
    }

    /**
     * POST /discovery/jobs/{id}/delete
     *
     * Deletes a discovery job. All findings for this job are removed via
     * CASCADE DELETE on discovery_findings.job_id.
     *
     * Redirects to /discovery/jobs on completion.
     */
    public function jobDelete(array $params = []): void
    {
        $id   = (int) ($params['id'] ?? 0);
        $repo = new DiscoveryRepository($this->container->get('db'));
        $job  = $repo->findJobById($id);

        if ($job === null) {
            http_response_code(404);
            echo 'Job not found.';
            return;
        }

        $repo->deleteJob($id);

        header('Location: /discovery/jobs');
        exit;
    }

    // -------------------------------------------------------------------------
    // Detail
    // -------------------------------------------------------------------------

    /**
     * GET /discovery/{id}
     *
     * Read-only detail page for a single discovery finding.
     * Shows finding metadata and the action panel (link / create / ignore).
     */
    public function show(array $params = []): void
    {
        $id   = (int) ($params['id'] ?? 0);
        $repo = new DiscoveryRepository($this->container->get('db'));

        $finding = $repo->findById($id);

        if ($finding === null) {
            http_response_code(404);
            echo 'Finding not found.';
            return;
        }

        // Load active devices for the "link to device" dropdown.
        $deviceRepo      = new DeviceRepository($this->container->get('db'));
        $devices         = $deviceRepo->findAll();
        $possibleMatches = $repo->possibleMatchesForFinding($id);

        $noteRepo = new NoteRepository($this->container->get('db'));
        $notes    = $noteRepo->findByEntity('finding', $id);

        [$user, $permissions, $appName, $displayName] = $this->principal();

        $pageTitle     = 'Finding — ' . htmlspecialchars($finding['ip_address']);
        $activeSection = 'Discovery';

        $viewsPath = __DIR__ . '/../../Views';

        ob_start();
        require $viewsPath . '/discovery/show.php';
        $content = ob_get_clean();

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        require $viewsPath . '/layouts/app.php';
    }

    // -------------------------------------------------------------------------
    // Notes
    // -------------------------------------------------------------------------

    /**
     * POST /discovery/{id}/notes
     *
     * Adds a note to the discovery finding. Redirects back to /discovery/{id}#notes.
     */
    public function addNote(array $params = []): void
    {
        $id   = (int) ($params['id'] ?? 0);
        $repo = new DiscoveryRepository($this->container->get('db'));

        $finding = $repo->findById($id);

        if ($finding === null) {
            http_response_code(404);
            echo 'Finding not found.';
            return;
        }

        [$user] = $this->principal();
        $content = $_POST['content'] ?? '';

        $noteRepo = new NoteRepository($this->container->get('db'));
        $service  = new NoteService($noteRepo);

        try {
            $service->addNote('finding', $id, (int) $user['id'], $content);
            header('Location: /discovery/' . $id . '#notes');
        } catch (\InvalidArgumentException $e) {
            header('Location: /discovery/' . $id . '?note_error=' . urlencode($e->getMessage()) . '#notes');
        }

        exit;
    }

    /**
     * POST /discovery/{id}/notes/{noteId}/delete
     *
     * Deletes a note from the discovery finding (author-only).
     * Redirects back to /discovery/{id}#notes.
     */
    public function deleteNote(array $params = []): void
    {
        $id     = (int) ($params['id']     ?? 0);
        $noteId = (int) ($params['noteId'] ?? 0);

        $repo    = new DiscoveryRepository($this->container->get('db'));
        $finding = $repo->findById($id);

        if ($finding === null) {
            http_response_code(404);
            echo 'Finding not found.';
            return;
        }

        [$user] = $this->principal();

        $noteRepo = new NoteRepository($this->container->get('db'));
        $service  = new NoteService($noteRepo);

        try {
            $service->removeNote($noteId, (int) $user['id']);
            header('Location: /discovery/' . $id . '#notes');
        } catch (\InvalidArgumentException $e) {
            header('Location: /discovery/' . $id . '?note_error=' . urlencode($e->getMessage()) . '#notes');
        }

        exit;
    }

    // -------------------------------------------------------------------------
    // Actions
    // -------------------------------------------------------------------------

    /**
     * POST /discovery/{id}/link
     *
     * Link a finding to an existing device.
     *
     * Sets finding.status = 'matched' and finding.matched_device_id.
     * Does NOT modify device_addresses — the IP is not automatically added to
     * the target device's address list. If the operator wants the IP recorded
     * on the device, they must add it via the device edit page.
     *
     * Redirects back to /discovery/{id} on success or failure.
     */
    public function link(array $params = []): void
    {
        $id   = (int) ($params['id'] ?? 0);
        $repo = new DiscoveryRepository($this->container->get('db'));

        $finding = $repo->findById($id);

        if ($finding === null) {
            http_response_code(404);
            echo 'Finding not found.';
            return;
        }

        $deviceId   = (int) ($_POST['device_id'] ?? 0);
        $deviceRepo = new DeviceRepository($this->container->get('db'));
        $device     = $deviceRepo->findById($deviceId);

        if ($device === null) {
            // Invalid or deleted device — bounce back to the detail page.
            header('Location: /discovery/' . $id . '?error=invalid_device');
            exit;
        }

        $repo->linkFindingToDevice($id, $deviceId);

        header('Location: /discovery/' . $id);
        exit;
    }

    /**
     * POST /discovery/{id}/ignore
     *
     * Mark a finding as ignored.
     *
     * The finding row is preserved for history. Ignored findings no longer
     * appear in the pending-review count. The matched_device_id is cleared.
     *
     * Redirects to /discovery on success.
     */
    public function ignore(array $params = []): void
    {
        $id   = (int) ($params['id'] ?? 0);
        $repo = new DiscoveryRepository($this->container->get('db'));

        $finding = $repo->findById($id);

        if ($finding === null) {
            http_response_code(404);
            echo 'Finding not found.';
            return;
        }

        $repo->markIgnored($id);

        header('Location: /discovery');
        exit;
    }

    // -------------------------------------------------------------------------
    // Create device from finding
    // -------------------------------------------------------------------------

    /**
     * GET /discovery/{id}/create-device
     *
     * Renders the "Create device from finding" form.
     * The address field is pre-filled with the finding's IP address.
     * The name field is pre-filled with the hostname if available.
     */
    public function createDeviceForm(array $params = []): void
    {
        $id   = (int) ($params['id'] ?? 0);
        $repo = new DiscoveryRepository($this->container->get('db'));

        $finding = $repo->findById($id);

        if ($finding === null) {
            http_response_code(404);
            echo 'Finding not found.';
            return;
        }

        // Prefill from finding data; hostname doubles as a name suggestion.
        $old = [
            'name'        => $finding['hostname'] ?? '',
            'address'     => $finding['ip_address'],
            'description' => '',
        ];

        $errors = [];

        [$user, $permissions, $appName, $displayName] = $this->principal();

        $pageTitle     = 'Create Device from Finding';
        $activeSection = 'Discovery';

        $viewsPath = __DIR__ . '/../../Views';

        ob_start();
        require $viewsPath . '/discovery/create-device.php';
        $content = ob_get_clean();

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        require $viewsPath . '/layouts/app.php';
    }

    /**
     * POST /discovery/{id}/create-device
     *
     * Validates and creates a new device from the finding's IP address.
     *
     * On success:
     *   1. Device is created via DeviceRepository::create() — this creates the
     *      devices row, a default management interface, and a primary address
     *      row in device_addresses containing the finding's IP.
     *   2. The finding is linked to the new device (status = 'matched').
     *   3. Redirects to /devices/{newId}.
     *
     * On validation failure: re-renders the form with errors.
     */
    public function createDevice(array $params = []): void
    {
        $id   = (int) ($params['id'] ?? 0);
        $repo = new DiscoveryRepository($this->container->get('db'));

        $finding = $repo->findById($id);

        if ($finding === null) {
            http_response_code(404);
            echo 'Finding not found.';
            return;
        }

        [$user, $permissions, $appName, $displayName] = $this->principal();

        $pageTitle     = 'Create Device from Finding';
        $activeSection = 'Discovery';

        $name        = trim($_POST['name']        ?? '');
        $address     = trim($_POST['address']     ?? '');
        $description = trim($_POST['description'] ?? '') ?: null;

        $errors = $this->validateDevice($name, $address);

        if (!empty($errors)) {
            $old = compact('name', 'address', 'description');

            $viewsPath = __DIR__ . '/../../Views';

            ob_start();
            require $viewsPath . '/discovery/create-device.php';
            $content = ob_get_clean();

            http_response_code(422);
            header('Content-Type: text/html; charset=utf-8');
            require $viewsPath . '/layouts/app.php';
            return;
        }

        // Create the device (also inserts management interface + primary address).
        $deviceRepo = new DeviceRepository($this->container->get('db'));
        $newDeviceId = $deviceRepo->create(compact('name', 'address', 'description'));

        // Link the finding to the new device.
        $repo->linkFindingToDevice($id, $newDeviceId);

        header('Location: /devices/' . $newDeviceId);
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
     * Validate discovery job form input.
     *
     * @return array<string, string>  Errors keyed by field name; empty on success.
     */
    private function validateJob(string $name, string $subnet): array
    {
        $errors = [];

        if ($name === '') {
            $errors['name'] = 'Job name is required.';
        } elseif (mb_strlen($name) > 128) {
            $errors['name'] = 'Job name must be 128 characters or fewer.';
        }

        if ($subnet === '') {
            $errors['subnet'] = 'Subnet is required.';
        } elseif (!$this->isValidCidr($subnet)) {
            $errors['subnet'] = 'Please enter a valid IPv4 CIDR subnet (e.g. 192.168.1.0/24).';
        }

        return $errors;
    }

    /**
     * Returns true if the value is a valid IPv4 CIDR notation (e.g. 192.168.1.0/24).
     */
    private function isValidCidr(string $value): bool
    {
        if (!preg_match('/^(.+)\/(\d+)$/', $value, $m)) {
            return false;
        }

        $prefix = (int) $m[2];

        return filter_var($m[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            && $prefix >= 0
            && $prefix <= 32;
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

        return (bool) preg_match(
            '/^(?:[a-zA-Z0-9](?:[a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?\.)*[a-zA-Z0-9](?:[a-zA-Z0-9\-]{0,61}[a-zA-Z0-9])?$/',
            $value
        );
    }
}
