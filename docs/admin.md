# NetMon Admin Area

## Overview

The `/admin` area is a separate, permission-gated section of the application for **system administration**. It is distinct from the user's `/profile` page.

| Area | URL | Purpose | Who manages it |
|---|---|---|---|
| Profile | `/profile` | Personal user settings (account, notification prefs, API tokens) | The logged-in user |
| Admin | `/admin` | System-managed settings (users, groups, permissions) | Administrators only |

---

## Authorization

All admin routes require two middleware layers, applied in order:

```php
['WebAuth', 'WebPermission:admin']
```

| Middleware | Role |
|---|---|
| `WebAuth` | Confirms a valid session exists; redirects to `/auth/login` if not |
| `WebPermission:admin` | Confirms the principal holds the `admin` permission; renders an HTML 403 page if not |

The `admin` permission is seeded at install time (see `database/seeds/AdminBootstrap.php`). It is assigned to the `admin` group, which the initial admin account belongs to.

### `WebPermission` middleware

`App\Middleware\WebPermission` is the HTML-friendly counterpart to `RequirePermission`:

- `RequirePermission` — always returns JSON 401/403. Use for AJAX / API routes.
- `WebPermission` — redirects on missing session, renders an HTML 403 page on missing permission. Use for browser HTML routes.

---

## Routes

### AdminController (landing page, audit log)

| Method | Path | Controller method | Description |
|---|---|---|---|
| GET | `/admin` | `AdminController::index()` | Admin landing page — summary counts and quick nav |
| GET | `/admin/audit` | `AdminController::audit()` | Audit log — last 500 entries, DataTable |

### PermissionController (full CRUD)

| Method | Path | Controller method | Description |
|---|---|---|---|
| GET | `/admin/permissions` | `PermissionController::index()` | Permissions list with Edit action |
| GET | `/admin/permissions/create` | `PermissionController::createForm()` | Create permission form |
| POST | `/admin/permissions` | `PermissionController::store()` | Handle permission create |
| GET | `/admin/permissions/{id}/edit` | `PermissionController::editForm()` | Edit form + danger zone |
| POST | `/admin/permissions/{id}` | `PermissionController::update()` | Handle permission update |
| POST | `/admin/permissions/{id}/delete` | `PermissionController::delete()` | Handle permission delete |

### UserController (full CRUD + group assignment)

| Method | Path | Controller method | Description |
|---|---|---|---|
| GET | `/admin/users` | `UserController::index()` | Users list with Create / Edit / Activate / Deactivate actions |
| GET | `/admin/users/create` | `UserController::createForm()` | Create user form |
| POST | `/admin/users` | `UserController::store()` | Handle user create |
| GET | `/admin/users/{id}/edit` | `UserController::editForm()` | Edit form — group assignment checkboxes |
| POST | `/admin/users/{id}` | `UserController::update()` | Handle group assignment |
| GET | `/admin/users/{id}/edit-account` | `UserController::editAccountForm()` | Edit account details (name, email, password) |
| POST | `/admin/users/{id}/account` | `UserController::updateAccount()` | Handle account update |
| POST | `/admin/users/{id}/activate` | `UserController::activate()` | Activate user |
| POST | `/admin/users/{id}/deactivate` | `UserController::deactivate()` | Deactivate user (safety-guarded) |

### GroupController (full CRUD + permission assignment)

| Method | Path | Controller method | Description |
|---|---|---|---|
| GET | `/admin/groups` | `GroupController::index()` | Groups list with Edit/Delete actions |
| GET | `/admin/groups/create` | `GroupController::createForm()` | Create group form |
| POST | `/admin/groups` | `GroupController::store()` | Handle group create |
| GET | `/admin/groups/{id}/edit` | `GroupController::editForm()` | Edit form + permission checkboxes + read-only members |
| POST | `/admin/groups/{id}` | `GroupController::update()` | Handle group update + permission sync |
| POST | `/admin/groups/{id}/delete` | `GroupController::delete()` | Handle group delete |

---

## Controllers

### `AdminController`

**File:** `app/Controllers/Admin/AdminController.php`

Handles the landing page and audit log. Read-only.

| Method | Route | Data loaded |
|---|---|---|
| `index()` | `GET /admin` | Count of users, groups, permissions |
| `audit()` | `GET /admin/audit` | `AuditLogRepository::findRecent(500)` |

### `PermissionController`

**File:** `app/Controllers/Admin/PermissionController.php`

Owns all permission-related admin routes.

Flash messages are stored in `$_SESSION['admin_flash']`.

Private helpers: `validatePermission()`, `ctx()`, `auditLog()`, `flash()`, `popFlash()`.

`ctx()` returns `[$viewsPath, $appName, $displayName, $permissions]`.

### `UserController`

**File:** `app/Controllers/Admin/UserController.php`

Owns all user-related admin routes.

Flash messages are stored in `$_SESSION['admin_flash']`.

Private helpers: `ctx()`, `checkAdminGuard()`, `checkDeactivateGuard()`, `flash()`, `popFlash()`.

`checkDeactivateGuard()` — mirrors `checkAdminGuard()` but for deactivation. Checks whether the target user is in the admin group, then counts other active admin users. Rejects deactivation if they are the last active administrator.

### `GroupController`

**File:** `app/Controllers/Admin/GroupController.php`

Owns all group-related routes. Thin controller — validation and guard logic live here, DB work in `GroupRepository`.

Flash messages are stored in `$_SESSION['admin_flash']`.

---

## User CRUD Behavior

### Create

`POST /admin/users` validates:
- Display name is required, max 100 characters
- Username is required, max 64 characters, matches `[a-z0-9._-]+` (case-insensitive), must be unique
- Email is required, valid format, must be unique
- Password is required, min 8 characters, must match confirmation

On validation failure: re-renders the create form with `is-invalid` field states. HTTP 422.

On success: inserts the user (is_active = 1), sets a flash message, redirects to the group assignment edit page for the new user.

### Edit Account

`POST /admin/users/{id}/account` handles display name, email, and optional password reset.

- Display name and email are validated as for create (email uniqueness check excludes the current user's own ID)
- Password fields are optional — if both are blank, the current password is unchanged
- If password is provided: min 8 characters, must match confirmation

On success: calls `UserRepository::update()` for name/email, then optionally `UserRepository::setPassword()` if a password was submitted.

### Activate / Deactivate

`POST /admin/users/{id}/activate` — sets `is_active = 1`. No guard needed.

`POST /admin/users/{id}/deactivate` — sets `is_active = 0`. Guarded by `checkDeactivateGuard()`:

1. Is the user a member of the `admin` group? If not, deactivation is always safe.
2. If yes: count other **active** users who currently belong to the `admin` group.
3. If that count is zero → reject with an error flash; do not deactivate.

The guard prevents a state where no active admin can log in.

---

## Permission CRUD Behavior

### Create

`POST /admin/permissions` validates:
- Code (name) is required
- Code is max 128 characters
- Code matches `/^[a-z][a-z0-9_]*(\.[a-z][a-z0-9_]*)*$/` — lowercase dot-separated identifiers (e.g. `devices.manage`, `files.manage`). Letters, digits, and underscores within each segment.
- Code must be unique (checked via `PermissionRepository::isCodeTaken()`)
- Description is optional, max 255 characters

On validation failure: re-renders the create form with `is-invalid` field states. HTTP 422.

On success: inserts the permission, logs `permission.create`, sets a flash message, redirects to `/admin/permissions`.

### Edit

`POST /admin/permissions/{id}` validates the same rules as create, excluding the current permission's own ID from the uniqueness check.

On success: updates the permission, logs `permission.update`, sets a flash message, redirects back to the edit page.

### Delete

`POST /admin/permissions/{id}/delete`

**Deletion guard:** `PermissionRepository::isInUse(int $id)` checks whether any row in `group_permissions` references this permission. If yes, deletion is refused and an error flash is displayed. The user is redirected to `/admin/permissions`.

The delete button in the edit view is also hidden (replaced with an informational message) when `group_count > 0`, providing a clear UI-level signal before the form is submitted.

**No cascading delete** is attempted — the FK constraint on `group_permissions` enforces referential integrity at the DB level as a safety net if the guard is bypassed.

On success: deletes the permission, logs `permission.delete`, sets a flash message, redirects to `/admin/permissions`.

---

## Group CRUD Behavior

### Create

`POST /admin/groups` validates:
- Name is required
- Name is max 64 characters
- Name matches `[a-z0-9._-]+` (case-insensitive)
- Name is unique (checked via `GroupRepository::isNameTaken()`)
- Description is max 255 characters (optional)

On validation failure: re-renders the create form with `is-invalid` field states and error messages. HTTP 422.

On success: inserts the group, sets a flash message, redirects to `/admin/groups`.

### Edit

`POST /admin/groups/{id}` handles two things in a single submit: group details (name, description) and the full permission set.

**Group details validation** applies the same rules as create, with the edit group's own ID excluded from the uniqueness check.

**Permission sync:** The form sends `permissions[]` as a set of permission IDs (unchecked checkboxes are absent — standard HTML). The controller:
1. Casts each submitted value to `int` and filters out non-positive values
2. Loads all known permission IDs from `PermissionRepository::findAll()`
3. Diffs the submitted set against the known set — any unknown ID produces a validation error and the whole form is rejected
4. On success, calls `GroupRepository::syncPermissions()` which atomically replaces the group's permission set

**System group name lock:** If the group being edited is a system group (e.g. `admin`), the name field is rendered disabled in the view and the controller ignores any submitted name, locking it to the original value. Permissions **can** be edited on system groups — there is no restriction. This is intentional: new application features add new permissions that the admin group needs to acquire.

On success: updates the group, syncs permissions, sets a flash message, redirects back to the edit page.

### Delete

`POST /admin/groups/{id}/delete`

**Cascade behavior:** Because SQLite is started with `PRAGMA foreign_keys=ON`, deleting a group automatically removes all associated rows in:
- `user_groups` (all member assignments for this group)
- `group_permissions` (all permission grants for this group)

No orphan cleanup is needed.

**System group guard:** Groups listed in `GroupRepository::SYSTEM_GROUPS` (currently `['admin']`) cannot be deleted. The controller checks this before calling `delete()` and returns an error flash message. The delete button is also hidden in the UI for system groups (enforced server-side as well for safety).

**Confirmation:** The groups list and the edit page both show a JavaScript `confirm()` dialog before the delete form submits.

---

## Group → Permission Assignment

### How it works

The group edit page (`GET /admin/groups/{id}/edit`) renders all available permissions as Bootstrap `form-check` checkboxes, grouped by permission prefix (e.g. all `users.*` permissions together, bare names under "General"). Currently assigned permissions are pre-checked.

When the admin saves the form (`POST /admin/groups/{id}`), the `update()` method:

1. Validates group name and description as usual
2. Reads `permissions[]` from `$_POST` — absent = empty array (all unchecked = remove all permissions, which is valid)
3. Casts each submitted value to `int`, filters non-positive
4. Loads all known permission IDs from `PermissionRepository::findAll()` and diffs the submitted set — any unknown ID rejects the form with an error
5. Calls `GroupRepository::syncPermissions($groupId, $validIds)`, which runs a transaction:
   - `DELETE FROM group_permissions WHERE group_id = ?`
   - `INSERT INTO group_permissions (group_id, permission_id) VALUES (?, ?)` for each ID
6. The permission set is replaced atomically — never partially updated

### How it affects authorization

`Gate::permissionsForUser(int $userId)` resolves permissions via:
```
users → user_groups → group_permissions → permissions
```

The next time a user authenticates (or their session is re-validated), the resolved permission set reflects the current `group_permissions` rows. Because the session stores the permission array in the principal envelope, **changes take effect on the user's next page load** — there is no immediate invalidation of active sessions.

This is acceptable for the current implementation. Future improvement: invalidate active sessions when group permissions change (requires session tracking).

### System groups and permissions

System groups (e.g. `admin`) **can have their permissions edited**. There is no restriction. This is intentional: new application features introduce new permissions, and the admin group needs to be able to acquire them. The only restriction for system groups is that their **name** cannot be changed.

---

## System Groups

`GroupRepository::SYSTEM_GROUPS` is a `public const` array of group names that are protected from deletion:

```php
public const SYSTEM_GROUPS = ['admin'];
```

The `admin` group is the bootstrap group. Removing it would break the authorization chain for the entire admin area.

The check is case-insensitive via `strtolower()` to guard against name collisions.

---

## Repositories

### `UserRepository`

**File:** `app/Models/UserRepository.php`

| Method | Description |
|---|---|
| `findAll()` | All users (including inactive) without `password_hash` |
| `findByIdAny(int $id)` | Single user by ID regardless of active status (admin use) |
| `findGroups(int $userId)` | Groups the user belongs to (id, name, description) |
| `isUsernameTaken(string $name, ?int $excludeId)` | Uniqueness check for create/edit |
| `isEmailTaken(string $email, ?int $excludeId)` | Uniqueness check for create/edit |
| `create(array $data)` | Insert user; returns new id |
| `update(int $id, array $data)` | Update display_name and email |
| `setPassword(int $id, string $passwordHash)` | Replace password hash |
| `setActive(int $id, bool $active)` | Set is_active flag |
| `syncGroups(int $userId, array $groupIds)` | Replace the user's full group membership in a PDO transaction (DELETE + INSERT) |

### `GroupRepository`

**File:** `app/Models/GroupRepository.php`

| Method | Description |
|---|---|
| `findAll()` | All groups with `member_count` and `permission_count` aggregates |
| `findById(int $id)` | Single group row or null |
| `findMembers(int $groupId)` | Users in this group (id, display_name, username, email, is_active) |
| `findPermissions(int $groupId)` | Permissions granted to this group (id, name, description) |
| `isNameTaken(string $name, ?int $excludeId)` | Uniqueness check for create/edit |
| `isSystemGroup(string $name)` | True if the name is in `SYSTEM_GROUPS` |
| `create(array $data)` | Insert group; returns new id |
| `update(int $id, array $data)` | Update name and description |
| `syncPermissions(int $groupId, array $permissionIds)` | Replace the group's full permission set in a PDO transaction (DELETE + INSERT) |
| `delete(int $id)` | Delete group (cascades user_groups + group_permissions) |

### `PermissionRepository`

**File:** `app/Models/PermissionRepository.php`

| Method | Returns | Description |
|---|---|---|
| `findAll()` | `array[]` | All permissions with `group_count` subquery aggregate |
| `findById(int $id)` | `?array` | Single permission row or null |
| `isCodeTaken(string $name, ?int $excludeId)` | `bool` | Uniqueness check for create/edit |
| `isInUse(int $id)` | `bool` | True if any group holds this permission |
| `create(array $data)` | `int` | Insert permission; returns new id |
| `update(int $id, array $data)` | `void` | Update code and description |
| `delete(int $id)` | `void` | Delete permission — callers must check `isInUse()` first |

---

## Views

| File | Route | Description |
|---|---|---|
| `app/Views/admin/index.php` | `GET /admin` | Stat cards + quick-nav links |
| `app/Views/admin/users.php` | `GET /admin/users` | DataTable: display_name, username, email, status, created; Create / Edit Account / Edit Groups / Activate / Deactivate actions |
| `app/Views/admin/user-create.php` | `GET /admin/users/create` | Create user form: display_name, username, email, password, confirm password |
| `app/Views/admin/user-edit.php` | `GET /admin/users/{id}/edit` | Group membership checkboxes + sub-nav to account details |
| `app/Views/admin/user-account-edit.php` | `GET /admin/users/{id}/edit-account` | Account details form: display_name, email + optional password reset |
| `app/Views/admin/groups.php` | `GET /admin/groups` | DataTable: name, description, counts, actions (edit/delete) |
| `app/Views/admin/group-create.php` | `GET /admin/groups/create` | Create form with inline validation |
| `app/Views/admin/group-edit.php` | `GET /admin/groups/{id}/edit` | Unified form: name/description + permission checkboxes (grouped by prefix) + read-only members + danger zone |
| `app/Views/admin/permissions.php` | `GET /admin/permissions` | DataTable: code, description, group_count, actions (edit) |
| `app/Views/admin/permission-create.php` | `GET /admin/permissions/create` | Create form: code, description |
| `app/Views/admin/permission-edit.php` | `GET /admin/permissions/{id}/edit` | Edit form + assigned groups summary + danger zone (delete, guarded) |
| `app/Views/admin/audit.php` | `GET /admin/audit` | DataTable: timestamp, actor, action, entity, meta summary |

All views use the shared layout and `NetMon.dt.init()` for DataTables where applicable.

---

## Navigation

The sidebar shows an **Administration** section conditionally for users who hold the `admin` permission:

```php
<?php if (in_array('admin', $permissions ?? [], true)): ?>
```

Links: Overview, Users, Groups, Permissions.

Users without `admin` see no Administration section.

---

## CSS

Admin-specific component styles:

- **LESS source:** `public/assets/less/components/admin.less`
- **Compiled:** appended to `public/assets/css/app.css`

| Class | Purpose |
|---|---|
| `.admin-stat-card` | Stat summary card on the landing page |
| `.admin-stat-icon` | Icon badge within a stat card |
| `.admin-stat-value` | Large numeric value in a stat card |
| `.admin-stat-label` | Label below the stat value |

---

## User → Group Assignment

### How it works

The user edit page (`GET /admin/users/{id}/edit`) shows a card-grid of all available groups as Bootstrap `form-check` checkboxes. Currently assigned groups are pre-checked and their cards are highlighted. A live JS counter updates the badge as checkboxes are toggled.

`POST /admin/users/{id}` in `UserController::update()`:
1. Reads `groups[]` from POST — absent = empty array = remove from all groups
2. Casts each to `int`, filters non-positive
3. Loads all known group IDs from `GroupRepository::findAll()` and diffs — any unknown ID rejects the form
4. Runs the admin safety guard (see below)
5. On success, calls `UserRepository::syncGroups()` — transactional DELETE + INSERT

### How it affects authorization

`Gate::permissionsForUser(int $userId)` resolves: `user → user_groups → group_permissions → permissions`. Changing a user's groups changes which permissions they receive on their **next page load** — active sessions are not immediately invalidated.

### Safety guard: last active admin

Before calling `syncGroups()`, `UserController::checkAdminGuard()` checks:

1. Locate the `admin` group by name (from `GroupRepository::SYSTEM_GROUPS`)
2. Is the `admin` group being removed from this user? (i.e. not in the submitted set)
3. If yes: count other **active** users who currently belong to the `admin` group
4. If that count is zero → reject with an error message; do not save

The guard counts only active users — inactive users cannot log in, so they cannot recover admin access. This prevents a locked-out state where no active admin exists.

The guard **does not** block removing a user from admin if at least one other active user holds the admin group.

---

## Audit Log

### Overview

The admin audit log is an append-only record of administrative actions. It is stored in the `admin_audit_log` table and is never modified or deleted through the application UI.

**File:** `app/Models/AuditLogRepository.php`

| Method | Description |
|---|---|
| `log(?int $userId, string $action, string $entityType, int $entityId, array $meta)` | Append one audit entry |
| `findRecent(int $limit = 500)` | Return the most recent entries, joined with actor display info |

### What Is Logged

| Action | Controller method | Entity type | Meta fields |
|---|---|---|---|
| `user.create` | `UserController::store()` | `user` | username, display_name, email |
| `user.account_update` | `UserController::updateAccount()` | `user` | display_name, email, password_changed |
| `user.activate` | `UserController::activate()` | `user` | username |
| `user.deactivate` | `UserController::deactivate()` | `user` | username |
| `group.create` | `GroupController::store()` | `group` | name, description |
| `group.update` | `GroupController::update()` | `group` | name, description, permission_count, permission_ids |
| `group.delete` | `GroupController::delete()` | `group` | name |
| `permission.create` | `PermissionController::store()` | `permission` | name |
| `permission.update` | `PermissionController::update()` | `permission` | name |
| `permission.delete` | `PermissionController::delete()` | `permission` | name |

Group membership changes (`UserController::update()` — `POST /admin/users/{id}`) are **not** logged in this phase. See Deferred section.

### How Logging Is Wired

Each controller has a private `auditLog()` helper:

```php
private function auditLog(?int $actorId, string $action, string $entityType, int $entityId, array $meta = []): void
{
    try {
        (new AuditLogRepository($this->container->get('db')))
            ->log($actorId, $action, $entityType, $entityId, $meta);
    } catch (\Throwable $e) {
        // Intentionally swallowed — audit logging must never abort the main operation.
    }
}
```

**Key design rule:** Logging is always wrapped in `try/catch`. A DB failure writing to the audit log must never cause the main operation to fail or display an error to the admin.

### How to Extend

To log a new action:

1. Call `$this->auditLog($actorId, 'entity.verb', 'entity', $id, [...])` after the operation succeeds.
2. Use a consistent dot-namespaced `action` string.
3. Keep `meta` small — names, key changed fields, and counts. Not full diffs.
4. No schema changes are needed unless you add new entity types that need indexing.

To add new entity types, follow the same pattern — `entity_type` is a free-form string and is not constrained by the schema.

### Limitations

- **No filtering UI** — the DataTable supports client-side search/sort only.
- **No export** — CSV/JSON export not yet implemented.
- **No diff viewer** — meta shows a flat key=value summary, not a before/after diff.
- **No group membership logging** — `user.groups_update` is deferred.
- **Session invalidation not logged** — passive auth changes (e.g. next-page-load permission updates) are not tracked.
- **Retention** — rows accumulate indefinitely; no cleanup policy is implemented.
- **Active sessions** — the actor is the authenticated admin at the time of the action. If the account is later deleted, `user_id` becomes NULL (FK `ON DELETE SET NULL`).

---

## What Is Implemented

- [x] `/admin` — read-only landing page
- [x] `/admin/audit` — audit log DataTable (last 500 entries, actor join, meta summary)
- [x] `AuditLogRepository::log()` / `findRecent()` — append-only, fail-silently in controllers
- [x] `/admin/users` — list with Create / Edit Account / Edit Groups / Activate / Deactivate actions
- [x] `/admin/users/create` — create user form (display_name, username, email, password)
- [x] `/admin/users/{id}/edit` — group assignment form + sub-nav to account details
- [x] `/admin/users/{id}/edit-account` — account details form (name, email, optional password reset)
- [x] `/admin/users/{id}/activate` + `/admin/users/{id}/deactivate` — toggle is_active flag
- [x] `UserRepository::isUsernameTaken()` / `isEmailTaken()` — uniqueness checks
- [x] `UserRepository::update()` / `setPassword()` / `setActive()` — account mutations
- [x] `UserRepository::syncGroups()` — transactional group membership sync
- [x] `UserRepository::findByIdAny()` — admin lookup ignoring active status
- [x] Admin safety guard — blocks removing last active admin from admin group (group assignment)
- [x] Admin deactivate guard — blocks deactivating last active admin user
- [x] `/admin/groups` — full CRUD (create, edit, delete)
- [x] Group → permission assignment via checkbox form on edit page
- [x] `GroupRepository::syncPermissions()` — transactional permission sync
- [x] `WebPermission` middleware for HTML-friendly permission enforcement
- [x] `GroupController` with create/edit/delete + system group guard
- [x] `GroupRepository` — full read/write + cascade-delete awareness
- [x] `/admin/permissions` — full CRUD (create, edit, delete) with deletion guard
- [x] `PermissionController` with create/edit/delete + in-use guard
- [x] `PermissionRepository` — full read/write + isCodeTaken + isInUse
- [x] Migration 0028 — adds `created_at` / `updated_at` to permissions table (backfilled)
- [x] `UserRepository`
- [x] Sidebar Administration section (conditional on `admin` permission)
- [x] Admin stat cards with theme-consistent styling
- [x] Flash messages via `$_SESSION['admin_flash']`

---

## Deferred (Future Phases)

| Item | Notes |
|---|---|
| Permission: split delete guard per-group | Currently only blocks if in use anywhere; future may allow inspecting which groups hold it |
| Password reset (admin-initiated) | No reset token flow yet (admin sets password directly from edit-account) |
| Session invalidation on membership change | Auth changes take effect at next login/page load |
| Audit log: group membership changes | `user.groups_update` not yet logged (group assignment via `POST /admin/users/{id}`) |
| Audit log: filtering UI | DataTable client-side search only; no server-side filter or date range |
| Audit log: export | No CSV/JSON export yet |
| Audit log: retention policy | Rows accumulate indefinitely; no cleanup or archival |
| Fine-grained admin permissions | Currently a single `admin` permission gates all of `/admin`; future may split into `groups.manage`, `users.manage`, etc. |
