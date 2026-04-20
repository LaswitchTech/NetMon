<?php

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Models\AuditLogRepository;
use App\Models\GroupRepository;
use App\Models\PermissionRepository;

/**
 * Admin group management controller.
 *
 * Owns all group-related admin routes:
 *
 *   GET  /admin/groups              → index()      Group list
 *   GET  /admin/groups/create       → createForm() Create form
 *   POST /admin/groups              → store()      Handle create
 *   GET  /admin/groups/{id}/edit    → editForm()   Edit form (name/desc + permission checkboxes + read-only members)
 *   POST /admin/groups/{id}         → update()     Handle edit (name, description, and permission sync in one pass)
 *   POST /admin/groups/{id}/delete  → delete()     Handle delete
 *
 * All routes are protected by ['WebAuth', 'WebPermission:admin'].
 */
class GroupController extends Controller
{
    // -------------------------------------------------------------------------
    // GET /admin/groups
    // -------------------------------------------------------------------------

    public function index(array $params = []): void
    {
        [$principal, $config, $viewsPath, $appName, $displayName, $permissions] = $this->ctx();

        $groups = (new GroupRepository($this->container->get('db')))->findAll();

        $pageTitle     = 'Groups';
        $activeSection = 'Admin Groups';
        $flash         = $this->popFlash();

        ob_start();
        require $viewsPath . '/admin/groups.php';
        $content = ob_get_clean();

        require $viewsPath . '/layouts/app.php';
    }

    // -------------------------------------------------------------------------
    // GET /admin/groups/create
    // -------------------------------------------------------------------------

    public function createForm(array $params = []): void
    {
        [$principal, $config, $viewsPath, $appName, $displayName, $permissions] = $this->ctx();

        $pageTitle     = 'New Group';
        $activeSection = 'Admin Groups';
        $errors        = [];
        $old           = [];

        ob_start();
        require $viewsPath . '/admin/group-create.php';
        $content = ob_get_clean();

        require $viewsPath . '/layouts/app.php';
    }

    // -------------------------------------------------------------------------
    // POST /admin/groups
    // -------------------------------------------------------------------------

    public function store(array $params = []): void
    {
        [$principal, $config, $viewsPath, $appName, $displayName, $permissions] = $this->ctx();

        $name        = trim($_POST['name']        ?? '');
        $description = trim($_POST['description'] ?? '');

        $errors = $this->validateGroup($name, $description);

        if (!empty($errors)) {
            $pageTitle     = 'New Group';
            $activeSection = 'Admin Groups';
            $old           = ['name' => $name, 'description' => $description];

            ob_start();
            require $viewsPath . '/admin/group-create.php';
            $content = ob_get_clean();

            http_response_code(422);
            require $viewsPath . '/layouts/app.php';
            return;
        }

        $repo    = new GroupRepository($this->container->get('db'));
        $newId   = $repo->create(['name' => $name, 'description' => $description]);
        $actorId = (int) ($this->container->get('principal')['user']['id'] ?? 0);
        $this->auditLog($actorId, 'group.create', 'group', $newId, [
            'name'        => $name,
            'description' => $description,
        ]);

        $this->flash('success', "Group \"{$name}\" created.");
        header('Location: /admin/groups');
        exit;
    }

    // -------------------------------------------------------------------------
    // GET /admin/groups/{id}/edit
    // -------------------------------------------------------------------------

    public function editForm(array $params = []): void
    {
        [$principal, $config, $viewsPath, $appName, $displayName, $permissions] = $this->ctx();

        $repo  = new GroupRepository($this->container->get('db'));
        $group = $repo->findById((int) ($params['id'] ?? 0));

        if ($group === null) {
            http_response_code(404);
            echo '<h1>404 — Group not found</h1>';
            return;
        }

        $db             = $this->container->get('db');
        $members        = $repo->findMembers((int) $group['id']);
        $allPermissions = (new PermissionRepository($db))->findAll();

        // Build the set of currently assigned permission IDs for checkbox pre-selection.
        $assignedPermIds = array_map('intval', array_column(
            $repo->findPermissions((int) $group['id']),
            'id'
        ));

        $isSystem = $repo->isSystemGroup($group['name']);

        $pageTitle     = 'Edit Group';
        $activeSection = 'Admin Groups';
        $errors        = [];
        $old           = [];
        $flash         = $this->popFlash();

        ob_start();
        require $viewsPath . '/admin/group-edit.php';
        $content = ob_get_clean();

        require $viewsPath . '/layouts/app.php';
    }

    // -------------------------------------------------------------------------
    // POST /admin/groups/{id}
    // -------------------------------------------------------------------------

    public function update(array $params = []): void
    {
        [$principal, $config, $viewsPath, $appName, $displayName, $permissions] = $this->ctx();

        $db    = $this->container->get('db');
        $repo  = new GroupRepository($db);
        $group = $repo->findById((int) ($params['id'] ?? 0));

        if ($group === null) {
            http_response_code(404);
            echo '<h1>404 — Group not found</h1>';
            return;
        }

        $isSystem    = $repo->isSystemGroup($group['name']);
        $name        = trim($_POST['name']        ?? '');
        $description = trim($_POST['description'] ?? '');

        // System groups cannot have their name changed (used as identifier in permission checks).
        if ($isSystem) {
            $name = $group['name'];
        }

        // --- Validate group fields ---
        $errors = $this->validateGroup($name, $description, (int) $group['id']);

        // --- Validate submitted permission IDs ---
        // Absent checkboxes = empty array = remove all permissions (valid).
        // Any ID that is not a positive integer or does not exist in the DB is rejected.
        $submittedPermIds = array_values(array_filter(
            array_map('intval', (array) ($_POST['permissions'] ?? [])),
            fn($id) => $id > 0
        ));

        $allPermissions = (new PermissionRepository($db))->findAll();
        $knownPermIds   = array_map('intval', array_column($allPermissions, 'id'));

        $unknownIds = array_diff($submittedPermIds, $knownPermIds);
        if (!empty($unknownIds)) {
            $errors['permissions'] = 'One or more selected permissions are invalid.';
        }

        if (!empty($errors)) {
            // Re-render the edit form with validation state.
            // Use the submitted permission IDs (not the saved ones) to repopulate checkboxes.
            $members         = $repo->findMembers((int) $group['id']);
            $assignedPermIds = $submittedPermIds;

            $pageTitle     = 'Edit Group';
            $activeSection = 'Admin Groups';
            $old           = ['name' => $name, 'description' => $description];
            $flash         = null;

            ob_start();
            require $viewsPath . '/admin/group-edit.php';
            $content = ob_get_clean();

            http_response_code(422);
            require $viewsPath . '/layouts/app.php';
            return;
        }

        // Save name/description then sync permissions atomically.
        $groupId = (int) $group['id'];
        $repo->update($groupId, ['name' => $name, 'description' => $description]);
        $repo->syncPermissions($groupId, $submittedPermIds);

        $actorId = (int) ($this->container->get('principal')['user']['id'] ?? 0);
        $this->auditLog($actorId, 'group.update', 'group', $groupId, [
            'name'             => $name,
            'description'      => $description,
            'permission_count' => count($submittedPermIds),
            'permission_ids'   => $submittedPermIds,
        ]);

        $this->flash('success', "Group \"{$name}\" updated.");
        header('Location: /admin/groups/' . $group['id'] . '/edit');
        exit;
    }

    // -------------------------------------------------------------------------
    // POST /admin/groups/{id}/delete
    // -------------------------------------------------------------------------

    public function delete(array $params = []): void
    {
        $repo  = new GroupRepository($this->container->get('db'));
        $group = $repo->findById((int) ($params['id'] ?? 0));

        if ($group === null) {
            $this->flash('error', 'Group not found.');
            header('Location: /admin/groups');
            exit;
        }

        if ($repo->isSystemGroup($group['name'])) {
            $this->flash('error', "System group \"{$group['name']}\" cannot be deleted.");
            header('Location: /admin/groups');
            exit;
        }

        $groupId = (int) $group['id'];
        $groupName = $group['name'];
        $repo->delete($groupId);

        $principal = $this->container->get('principal');
        $actorId   = (int) ($principal['user']['id'] ?? 0);
        $this->auditLog($actorId, 'group.delete', 'group', $groupId, [
            'name' => $groupName,
        ]);

        $this->flash('success', "Group \"{$groupName}\" deleted.");
        header('Location: /admin/groups');
        exit;
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Build and return the standard context variables used by every admin view.
     * Returns [$principal, $config, $viewsPath, $appName, $displayName, $permissions].
     */
    private function ctx(): array
    {
        $principal   = $this->container->get('principal');
        $config      = $this->container->get('config');
        $viewsPath   = __DIR__ . '/../../Views';
        $appName     = $config['name'] ?? 'NetMon';
        $displayName = $principal['user']['display_name'] ?? $principal['user']['username'];
        $permissions = $principal['permissions'];

        return [$principal, $config, $viewsPath, $appName, $displayName, $permissions];
    }

    /**
     * Validate group name and description.
     * Returns an array of field => message pairs; empty means valid.
     *
     * @param int|null $groupId  When editing, pass the group ID to allow same-name check.
     */
    private function validateGroup(string $name, string $description, ?int $groupId = null): array
    {
        $errors = [];
        $repo   = new GroupRepository($this->container->get('db'));

        if ($name === '') {
            $errors['name'] = 'Group name is required.';
        } elseif (strlen($name) > 64) {
            $errors['name'] = 'Group name must be 64 characters or fewer.';
        } elseif (!preg_match('/^[a-z0-9._\-]+$/i', $name)) {
            $errors['name'] = 'Group name may only contain letters, numbers, dots, dashes, and underscores.';
        } elseif ($repo->isNameTaken($name, $groupId)) {
            $errors['name'] = 'A group with that name already exists.';
        }

        if (strlen($description) > 255) {
            $errors['description'] = 'Description must be 255 characters or fewer.';
        }

        return $errors;
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
            // Intentionally swallowed — audit logging must never abort the main operation.
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
