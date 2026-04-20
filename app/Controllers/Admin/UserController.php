<?php

namespace App\Controllers\Admin;

use App\Core\Controller;
use App\Models\AuditLogRepository;
use App\Models\GroupRepository;
use App\Models\UserRepository;

/**
 * Admin user management controller.
 *
 * Owns all user-related admin routes:
 *
 *   GET  /admin/users                    → index()           Users list
 *   GET  /admin/users/create             → createForm()      Create user form
 *   POST /admin/users                    → store()           Handle user create
 *   GET  /admin/users/{id}/edit          → editForm()        Edit form (group assignment)
 *   POST /admin/users/{id}               → update()          Handle group assignment
 *   GET  /admin/users/{id}/edit-account  → editAccountForm() Edit account details form
 *   POST /admin/users/{id}/account       → updateAccount()   Handle account update
 *   POST /admin/users/{id}/activate      → activate()        Activate user
 *   POST /admin/users/{id}/deactivate    → deactivate()      Deactivate user
 *
 * All routes are protected by ['WebAuth', 'WebPermission:admin'].
 */
class UserController extends Controller
{
    // -------------------------------------------------------------------------
    // GET /admin/users
    // -------------------------------------------------------------------------

    public function index(array $params = []): void
    {
        [$principal, $config, $viewsPath, $appName, $displayName, $permissions] = $this->ctx();

        $users = (new UserRepository($this->container->get('db')))->findAll();

        $pageTitle     = 'Users';
        $activeSection = 'Admin Users';
        $flash         = $this->popFlash();

        ob_start();
        require $viewsPath . '/admin/users.php';
        $content = ob_get_clean();

        require $viewsPath . '/layouts/app.php';
    }

    // -------------------------------------------------------------------------
    // GET /admin/users/create
    // -------------------------------------------------------------------------

    public function createForm(array $params = []): void
    {
        [$principal, $config, $viewsPath, $appName, $displayName, $permissions] = $this->ctx();

        $pageTitle     = 'Create User';
        $activeSection = 'Admin Users';
        $errors        = [];
        $old           = [];
        $flash         = $this->popFlash();

        ob_start();
        require $viewsPath . '/admin/user-create.php';
        $content = ob_get_clean();

        require $viewsPath . '/layouts/app.php';
    }

    // -------------------------------------------------------------------------
    // POST /admin/users
    // -------------------------------------------------------------------------

    public function store(array $params = []): void
    {
        [$principal, $config, $viewsPath, $appName, $displayName, $permissions] = $this->ctx();

        $db       = $this->container->get('db');
        $userRepo = new UserRepository($db);

        $old = [
            'display_name' => trim($_POST['display_name'] ?? ''),
            'username'     => trim($_POST['username']     ?? ''),
            'email'        => trim($_POST['email']        ?? ''),
        ];

        $password        = $_POST['password']        ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';

        $errors = [];

        // --- Validate display_name ---
        if ($old['display_name'] === '') {
            $errors['display_name'] = 'Display name is required.';
        } elseif (mb_strlen($old['display_name']) > 100) {
            $errors['display_name'] = 'Display name must be 100 characters or fewer.';
        }

        // --- Validate username ---
        if ($old['username'] === '') {
            $errors['username'] = 'Username is required.';
        } elseif (!preg_match('/^[a-z0-9._-]+$/i', $old['username'])) {
            $errors['username'] = 'Username may only contain letters, digits, dots, underscores, and hyphens.';
        } elseif (mb_strlen($old['username']) > 64) {
            $errors['username'] = 'Username must be 64 characters or fewer.';
        } elseif ($userRepo->isUsernameTaken($old['username'])) {
            $errors['username'] = 'That username is already taken.';
        }

        // --- Validate email ---
        if ($old['email'] === '') {
            $errors['email'] = 'Email address is required.';
        } elseif (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Please enter a valid email address.';
        } elseif ($userRepo->isEmailTaken($old['email'])) {
            $errors['email'] = 'That email address is already registered.';
        }

        // --- Validate password ---
        if ($password === '') {
            $errors['password'] = 'Password is required.';
        } elseif (mb_strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters.';
        } elseif ($password !== $passwordConfirm) {
            $errors['password_confirm'] = 'Passwords do not match.';
        }

        if (!empty($errors)) {
            $pageTitle     = 'Create User';
            $activeSection = 'Admin Users';
            $flash         = null;

            ob_start();
            require $viewsPath . '/admin/user-create.php';
            $content = ob_get_clean();

            http_response_code(422);
            require $viewsPath . '/layouts/app.php';
            return;
        }

        $newId = $userRepo->create([
            'display_name'  => $old['display_name'],
            'username'      => $old['username'],
            'email'         => $old['email'],
            'password_hash' => password_hash($password, PASSWORD_BCRYPT),
            'is_active'     => 1,
        ]);

        $actorId = (int) ($this->container->get('principal')['user']['id'] ?? 0);
        $this->auditLog($actorId, 'user.create', 'user', $newId, [
            'username'     => $old['username'],
            'display_name' => $old['display_name'],
            'email'        => $old['email'],
        ]);

        $this->flash('success', 'User "' . $old['username'] . '" created successfully.');
        header('Location: /admin/users/' . $newId . '/edit');
        exit;
    }

    // -------------------------------------------------------------------------
    // GET /admin/users/{id}/edit
    // -------------------------------------------------------------------------

    public function editForm(array $params = []): void
    {
        [$principal, $config, $viewsPath, $appName, $displayName, $permissions] = $this->ctx();

        $db       = $this->container->get('db');
        $userRepo = new UserRepository($db);
        $editUser = $userRepo->findByIdAny((int) ($params['id'] ?? 0));

        if ($editUser === null) {
            http_response_code(404);
            echo '<h1>404 &mdash; User not found</h1>';
            return;
        }

        $allGroups        = (new GroupRepository($db))->findAll();
        $assignedGroupIds = array_map('intval', array_column(
            $userRepo->findGroups((int) $editUser['id']),
            'id'
        ));

        $pageTitle     = 'Edit User';
        $activeSection = 'Admin Users';
        $errors        = [];
        $flash         = $this->popFlash();

        ob_start();
        require $viewsPath . '/admin/user-edit.php';
        $content = ob_get_clean();

        require $viewsPath . '/layouts/app.php';
    }

    // -------------------------------------------------------------------------
    // POST /admin/users/{id}
    // -------------------------------------------------------------------------

    public function update(array $params = []): void
    {
        [$principal, $config, $viewsPath, $appName, $displayName, $permissions] = $this->ctx();

        $db       = $this->container->get('db');
        $userRepo = new UserRepository($db);
        $editUser = $userRepo->findByIdAny((int) ($params['id'] ?? 0));

        if ($editUser === null) {
            http_response_code(404);
            echo '<h1>404 &mdash; User not found</h1>';
            return;
        }

        $allGroups     = (new GroupRepository($db))->findAll();
        $knownGroupIds = array_map('intval', array_column($allGroups, 'id'));

        // Absent checkboxes = empty array = remove from all groups (valid — guarded below).
        $submittedGroupIds = array_values(array_filter(
            array_map('intval', (array) ($_POST['groups'] ?? [])),
            fn($id) => $id > 0
        ));

        $errors = [];

        $unknownIds = array_diff($submittedGroupIds, $knownGroupIds);
        if (!empty($unknownIds)) {
            $errors['groups'] = 'One or more selected groups are invalid.';
        }

        // Safety guard: at least one active admin must remain.
        if (empty($errors)) {
            $guardError = $this->checkAdminGuard((int) $editUser['id'], $submittedGroupIds);
            if ($guardError !== null) {
                $errors['groups'] = $guardError;
            }
        }

        if (!empty($errors)) {
            $assignedGroupIds = $submittedGroupIds;

            $pageTitle     = 'Edit User';
            $activeSection = 'Admin Users';
            $flash         = null;

            ob_start();
            require $viewsPath . '/admin/user-edit.php';
            $content = ob_get_clean();

            http_response_code(422);
            require $viewsPath . '/layouts/app.php';
            return;
        }

        $userRepo->syncGroups((int) $editUser['id'], $submittedGroupIds);

        $this->flash('success', 'Group membership updated for ' . $editUser['display_name'] . '.');
        header('Location: /admin/users/' . $editUser['id'] . '/edit');
        exit;
    }

    // -------------------------------------------------------------------------
    // GET /admin/users/{id}/edit-account
    // -------------------------------------------------------------------------

    public function editAccountForm(array $params = []): void
    {
        [$principal, $config, $viewsPath, $appName, $displayName, $permissions] = $this->ctx();

        $userRepo = new UserRepository($this->container->get('db'));
        $editUser = $userRepo->findByIdAny((int) ($params['id'] ?? 0));

        if ($editUser === null) {
            http_response_code(404);
            echo '<h1>404 &mdash; User not found</h1>';
            return;
        }

        $pageTitle     = 'Edit Account';
        $activeSection = 'Admin Users';
        $errors        = [];
        $old           = [
            'display_name' => $editUser['display_name'],
            'email'        => $editUser['email'],
        ];
        $flash         = $this->popFlash();

        ob_start();
        require $viewsPath . '/admin/user-account-edit.php';
        $content = ob_get_clean();

        require $viewsPath . '/layouts/app.php';
    }

    // -------------------------------------------------------------------------
    // POST /admin/users/{id}/account
    // -------------------------------------------------------------------------

    public function updateAccount(array $params = []): void
    {
        [$principal, $config, $viewsPath, $appName, $displayName, $permissions] = $this->ctx();

        $db       = $this->container->get('db');
        $userRepo = new UserRepository($db);
        $editUser = $userRepo->findByIdAny((int) ($params['id'] ?? 0));

        if ($editUser === null) {
            http_response_code(404);
            echo '<h1>404 &mdash; User not found</h1>';
            return;
        }

        $userId = (int) $editUser['id'];

        $old = [
            'display_name' => trim($_POST['display_name'] ?? ''),
            'email'        => trim($_POST['email']        ?? ''),
        ];

        $password        = $_POST['password']        ?? '';
        $passwordConfirm = $_POST['password_confirm'] ?? '';

        $errors = [];

        // --- Validate display_name ---
        if ($old['display_name'] === '') {
            $errors['display_name'] = 'Display name is required.';
        } elseif (mb_strlen($old['display_name']) > 100) {
            $errors['display_name'] = 'Display name must be 100 characters or fewer.';
        }

        // --- Validate email ---
        if ($old['email'] === '') {
            $errors['email'] = 'Email address is required.';
        } elseif (!filter_var($old['email'], FILTER_VALIDATE_EMAIL)) {
            $errors['email'] = 'Please enter a valid email address.';
        } elseif ($userRepo->isEmailTaken($old['email'], $userId)) {
            $errors['email'] = 'That email address is already registered.';
        }

        // --- Validate password (optional — only if provided) ---
        if ($password !== '') {
            if (mb_strlen($password) < 8) {
                $errors['password'] = 'Password must be at least 8 characters.';
            } elseif ($password !== $passwordConfirm) {
                $errors['password_confirm'] = 'Passwords do not match.';
            }
        }

        if (!empty($errors)) {
            $pageTitle     = 'Edit Account';
            $activeSection = 'Admin Users';
            $flash         = null;

            ob_start();
            require $viewsPath . '/admin/user-account-edit.php';
            $content = ob_get_clean();

            http_response_code(422);
            require $viewsPath . '/layouts/app.php';
            return;
        }

        $userRepo->update($userId, [
            'display_name' => $old['display_name'],
            'email'        => $old['email'],
        ]);

        $passwordChanged = false;
        if ($password !== '') {
            $userRepo->setPassword($userId, password_hash($password, PASSWORD_BCRYPT));
            $passwordChanged = true;
        }

        $actorId = (int) ($this->container->get('principal')['user']['id'] ?? 0);
        $this->auditLog($actorId, 'user.account_update', 'user', $userId, [
            'display_name'    => $old['display_name'],
            'email'           => $old['email'],
            'password_changed' => $passwordChanged,
        ]);

        $this->flash('success', 'Account updated for ' . $old['display_name'] . '.');
        header('Location: /admin/users/' . $userId . '/edit-account');
        exit;
    }

    // -------------------------------------------------------------------------
    // POST /admin/users/{id}/activate
    // -------------------------------------------------------------------------

    public function activate(array $params = []): void
    {
        $userRepo = new UserRepository($this->container->get('db'));
        $editUser = $userRepo->findByIdAny((int) ($params['id'] ?? 0));

        if ($editUser === null) {
            http_response_code(404);
            echo '<h1>404 &mdash; User not found</h1>';
            return;
        }

        $userId = (int) $editUser['id'];
        $userRepo->setActive($userId, true);

        $actorId = (int) ($this->container->get('principal')['user']['id'] ?? 0);
        $this->auditLog($actorId, 'user.activate', 'user', $userId, [
            'username' => $editUser['username'],
        ]);

        $this->flash('success', htmlspecialchars($editUser['display_name']) . ' has been activated.');
        header('Location: /admin/users');
        exit;
    }

    // -------------------------------------------------------------------------
    // POST /admin/users/{id}/deactivate
    // -------------------------------------------------------------------------

    public function deactivate(array $params = []): void
    {
        $db       = $this->container->get('db');
        $userRepo = new UserRepository($db);
        $editUser = $userRepo->findByIdAny((int) ($params['id'] ?? 0));

        if ($editUser === null) {
            http_response_code(404);
            echo '<h1>404 &mdash; User not found</h1>';
            return;
        }

        $userId = (int) $editUser['id'];

        // Safety guard: cannot deactivate the last active admin.
        $guardError = $this->checkDeactivateGuard($userId);
        if ($guardError !== null) {
            $this->flash('error', $guardError);
            header('Location: /admin/users');
            exit;
        }

        $userRepo->setActive($userId, false);

        $actorId = (int) ($this->container->get('principal')['user']['id'] ?? 0);
        $this->auditLog($actorId, 'user.deactivate', 'user', $userId, [
            'username' => $editUser['username'],
        ]);

        $this->flash('success', htmlspecialchars($editUser['display_name']) . ' has been deactivated.');
        header('Location: /admin/users');
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
     * Guard against removing admin access from the last active admin user.
     *
     * If the submission would remove the user from the 'admin' group, this method
     * counts how many other active users still belong to the 'admin' group.
     * If none remain, the operation is rejected.
     *
     * Returns null if the operation is safe, or an error message string if not.
     */
    private function checkAdminGuard(int $userId, array $newGroupIds): ?string
    {
        $db = $this->container->get('db');

        $adminGroup = $db->fetchOne(
            'SELECT id FROM groups WHERE name = ? LIMIT 1',
            [current(GroupRepository::SYSTEM_GROUPS)]   // 'admin'
        );

        if ($adminGroup === null) {
            return null;
        }

        $adminGroupId = (int) $adminGroup['id'];

        if (in_array($adminGroupId, $newGroupIds, true)) {
            return null;
        }

        $row = $db->fetchOne(
            'SELECT COUNT(*) AS cnt
             FROM user_groups ug
             JOIN users u ON u.id = ug.user_id
             WHERE ug.group_id = ? AND u.id != ? AND u.is_active = 1',
            [$adminGroupId, $userId]
        );

        if ((int) ($row['cnt'] ?? 0) === 0) {
            return 'Cannot remove admin group: this user is the last active administrator. '
                 . 'Assign admin group to another active user first.';
        }

        return null;
    }

    /**
     * Guard against deactivating the last active admin user.
     *
     * Counts other active users who belong to the 'admin' group.
     * If none exist, deactivation is rejected.
     *
     * Returns null if safe, or an error message string if not.
     */
    private function checkDeactivateGuard(int $userId): ?string
    {
        $db = $this->container->get('db');

        $adminGroup = $db->fetchOne(
            'SELECT id FROM groups WHERE name = ? LIMIT 1',
            [current(GroupRepository::SYSTEM_GROUPS)]
        );

        if ($adminGroup === null) {
            return null;
        }

        $adminGroupId = (int) $adminGroup['id'];

        // Is this user even in the admin group?
        $membership = $db->fetchOne(
            'SELECT 1 FROM user_groups WHERE user_id = ? AND group_id = ? LIMIT 1',
            [$userId, $adminGroupId]
        );

        if ($membership === null) {
            return null; // Not an admin — deactivation is always safe.
        }

        // Count other active admins.
        $row = $db->fetchOne(
            'SELECT COUNT(*) AS cnt
             FROM user_groups ug
             JOIN users u ON u.id = ug.user_id
             WHERE ug.group_id = ? AND u.id != ? AND u.is_active = 1',
            [$adminGroupId, $userId]
        );

        if ((int) ($row['cnt'] ?? 0) === 0) {
            return 'Cannot deactivate this user: they are the last active administrator. '
                 . 'Assign admin group to another active user first.';
        }

        return null;
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
