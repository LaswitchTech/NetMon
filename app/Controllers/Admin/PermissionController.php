<?php

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Models\AuditLogRepository;
use App\Models\PermissionRepository;

/**
 * Admin permission management controller.
 *
 * Owns all permission-related admin routes:
 *
 *   GET  /admin/permissions              → index()      Permission list
 *   GET  /admin/permissions/create       → createForm() Create form
 *   POST /admin/permissions              → store()      Handle create
 *   GET  /admin/permissions/{id}/edit    → editForm()   Edit form
 *   POST /admin/permissions/{id}         → update()     Handle edit
 *   POST /admin/permissions/{id}/delete  → delete()     Handle delete
 *
 * All routes are protected by ['WebAuth', 'WebPermission:admin'].
 *
 * Validation rules:
 *   - code (name): required, max 128 chars, pattern /^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$/
 *   - description: optional, max 255 chars
 *
 * Deletion guard:
 *   - A permission assigned to any group cannot be deleted.
 *   - isInUse() is checked before delete(); a clear error is shown if blocked.
 */
class PermissionController extends Controller
{
    // -------------------------------------------------------------------------
    // GET /admin/permissions
    // -------------------------------------------------------------------------

    public function index(array $params = []): void
    {
        [$viewsPath, $appName, $displayName, $permissions] = $this->ctx();

        $permList = (new PermissionRepository($this->container->get('db')))->findAll();

        $pageTitle     = 'Permissions';
        $activeSection = 'Admin Permissions';
        $flash         = $this->popFlash();

        ob_start();
        require $viewsPath . '/admin/permissions.php';
        $content = ob_get_clean();

        require $viewsPath . '/layouts/app.php';
    }

    // -------------------------------------------------------------------------
    // GET /admin/permissions/create
    // -------------------------------------------------------------------------

    public function createForm(array $params = []): void
    {
        [$viewsPath, $appName, $displayName, $permissions] = $this->ctx();

        $pageTitle     = 'New Permission';
        $activeSection = 'Admin Permissions';
        $errors        = [];
        $old           = [];
        $flash         = $this->popFlash();

        ob_start();
        require $viewsPath . '/admin/permission-create.php';
        $content = ob_get_clean();

        require $viewsPath . '/layouts/app.php';
    }

    // -------------------------------------------------------------------------
    // POST /admin/permissions
    // -------------------------------------------------------------------------

    public function store(array $params = []): void
    {
        [$viewsPath, $appName, $displayName, $permissions] = $this->ctx();

        $repo = new PermissionRepository($this->container->get('db'));

        $old = [
            'name'        => trim($_POST['name']        ?? ''),
            'description' => trim($_POST['description'] ?? ''),
        ];

        $errors = $this->validatePermission($old['name'], $old['description'], $repo);

        if (!empty($errors)) {
            $pageTitle     = 'New Permission';
            $activeSection = 'Admin Permissions';
            $flash         = null;

            ob_start();
            require $viewsPath . '/admin/permission-create.php';
            $content = ob_get_clean();

            http_response_code(422);
            require $viewsPath . '/layouts/app.php';
            return;
        }

        $newId = $repo->create([
            'name'        => $old['name'],
            'description' => $old['description'],
        ]);

        $actorId = (int) ($this->container->get('principal')['user']['id'] ?? 0);
        $this->auditLog($actorId, 'permission.create', 'permission', $newId, [
            'name' => $old['name'],
        ]);

        $this->flash('success', "Permission \"{$old['name']}\" created.");
        header('Location: /admin/permissions');
        exit;
    }

    // -------------------------------------------------------------------------
    // GET /admin/permissions/{id}/edit
    // -------------------------------------------------------------------------

    public function editForm(array $params = []): void
    {
        [$viewsPath, $appName, $displayName, $permissions] = $this->ctx();

        $repo = new PermissionRepository($this->container->get('db'));
        $perm = $repo->findById((int) ($params['id'] ?? 0));

        if ($perm === null) {
            http_response_code(404);
            echo '<h1>404 &mdash; Permission not found</h1>';
            return;
        }

        $pageTitle     = 'Edit Permission';
        $activeSection = 'Admin Permissions';
        $errors        = [];
        $old           = [
            'name'        => $perm['name'],
            'description' => $perm['description'] ?? '',
        ];
        $flash         = $this->popFlash();

        ob_start();
        require $viewsPath . '/admin/permission-edit.php';
        $content = ob_get_clean();

        require $viewsPath . '/layouts/app.php';
    }

    // -------------------------------------------------------------------------
    // POST /admin/permissions/{id}
    // -------------------------------------------------------------------------

    public function update(array $params = []): void
    {
        [$viewsPath, $appName, $displayName, $permissions] = $this->ctx();

        $db   = $this->container->get('db');
        $repo = new PermissionRepository($db);
        $perm = $repo->findById((int) ($params['id'] ?? 0));

        if ($perm === null) {
            http_response_code(404);
            echo '<h1>404 &mdash; Permission not found</h1>';
            return;
        }

        $permId = (int) $perm['id'];

        $old = [
            'name'        => trim($_POST['name']        ?? ''),
            'description' => trim($_POST['description'] ?? ''),
        ];

        $errors = $this->validatePermission($old['name'], $old['description'], $repo, $permId);

        if (!empty($errors)) {
            $pageTitle     = 'Edit Permission';
            $activeSection = 'Admin Permissions';
            $flash         = null;

            ob_start();
            require $viewsPath . '/admin/permission-edit.php';
            $content = ob_get_clean();

            http_response_code(422);
            require $viewsPath . '/layouts/app.php';
            return;
        }

        $repo->update($permId, [
            'name'        => $old['name'],
            'description' => $old['description'],
        ]);

        $actorId = (int) ($this->container->get('principal')['user']['id'] ?? 0);
        $this->auditLog($actorId, 'permission.update', 'permission', $permId, [
            'name' => $old['name'],
        ]);

        $this->flash('success', "Permission \"{$old['name']}\" updated.");
        header('Location: /admin/permissions/' . $permId . '/edit');
        exit;
    }

    // -------------------------------------------------------------------------
    // POST /admin/permissions/{id}/delete
    // -------------------------------------------------------------------------

    public function delete(array $params = []): void
    {
        $db   = $this->container->get('db');
        $repo = new PermissionRepository($db);
        $perm = $repo->findById((int) ($params['id'] ?? 0));

        if ($perm === null) {
            $this->flash('error', 'Permission not found.');
            header('Location: /admin/permissions');
            exit;
        }

        $permId   = (int) $perm['id'];
        $permName = $perm['name'];

        // Guard: cannot delete a permission that is assigned to any group.
        if ($repo->isInUse($permId)) {
            $this->flash('error', "Cannot delete \"{$permName}\": it is currently assigned to one or more groups. Remove it from all groups first.");
            header('Location: /admin/permissions');
            exit;
        }

        $repo->delete($permId);

        $actorId = (int) ($this->container->get('principal')['user']['id'] ?? 0);
        $this->auditLog($actorId, 'permission.delete', 'permission', $permId, [
            'name' => $permName,
        ]);

        $this->flash('success', "Permission \"{$permName}\" deleted.");
        header('Location: /admin/permissions');
        exit;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Validate permission name and description.
     *
     * Rules for name (code):
     *   - required
     *   - max 128 characters
     *   - pattern: /^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$/
     *     (lowercase dot-separated identifiers, e.g. "devices.manage")
     *   - unique (excludes $editId when editing)
     *
     * Rules for description:
     *   - optional, max 255 characters
     *
     * @return array<string, string>  Field → error message; empty = valid.
     */
    private function validatePermission(
        string              $name,
        string              $description,
        PermissionRepository $repo,
        ?int                $editId = null
    ): array {
        $errors = [];

        if ($name === '') {
            $errors['name'] = 'Permission code is required.';
        } elseif (strlen($name) > 128) {
            $errors['name'] = 'Permission code must be 128 characters or fewer.';
        } elseif (!preg_match('/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$/', $name)) {
            $errors['name'] = 'Permission code must be lowercase dot-separated identifiers (e.g. "devices.manage"). Letters, digits, and underscores only within each segment.';
        } elseif ($repo->isCodeTaken($name, $editId)) {
            $errors['name'] = 'A permission with that code already exists.';
        }

        if (strlen($description) > 255) {
            $errors['description'] = 'Description must be 255 characters or fewer.';
        }

        return $errors;
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
     * Write an audit log entry. Fails silently — never breaks the main operation.
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
     * Write a flash message to the session for the next request.
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
