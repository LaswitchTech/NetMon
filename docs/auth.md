# NetMon Authentication & Authorization

## Overview

Authentication and authorization are split into distinct, composable layers:

| Layer | Class(es) | Responsibility |
|---|---|---|
| Provider | `AuthProviderInterface`, `LocalAuthProvider` | Verify credentials; return a safe user array |
| Session service | `AuthService` | PHP session lifecycle |
| Token service | `TokenService` | Generate / verify / revoke API tokens |
| Gate | `Gate` | Resolve and evaluate user permissions |
| Middleware | `SessionAuth`, `TokenAuth`, `RequirePermission` | Protect routes; build principal |
| Controllers | `AuthController`, `TokenController` | HTTP endpoints |

---

## Principal Envelope

Auth middleware writes a **principal** to the container (`$container->set('principal', ...)`).
Every downstream middleware and controller reads from it — without caring how the user authenticated.

```php
[
    'user'        => [           // safe user fields, never contains password_hash
        'id', 'display_name', 'username', 'email', 'is_active', 'created_at', 'updated_at'
    ],
    'auth_method' => 'session' | 'token',
    'token'       => null | [ /* token record, no hash */ ],
    'permissions' => ['admin', 'users.view', ...],   // resolved from group_permissions
]
```

---

## Session Authentication

**Class:** `App\Auth\AuthService`

| Method | Description |
|---|---|
| `login(array $credentials): ?array` | Attempt login; returns safe user or null |
| `logout(): void` | Destroy session; expire cookie |
| `user(): ?array` | Return current user from session; null if not logged in |
| `check(): bool` | True if a user is logged in |

Session security:
- Session started **lazily** on first method call.
- Session ID regenerated on login (prevents fixation).
- `session.use_strict_mode = 1` rejects unknown IDs.
- Cookie is `httponly`, `SameSite=Lax`. Set `secure = true` in `config/auth.php` for HTTPS.
- User is **re-fetched from DB on every request** — deactivated accounts are denied immediately.

---

## Token Authentication

**Class:** `App\Auth\TokenService`

| Method | Description |
|---|---|
| `generate(int $userId, string $name, ?string $expiresAt): array` | Create token; returns `['raw' => ..., 'record' => ...]` |
| `verify(string $rawToken): ?array` | Verify token; returns principal or null |
| `revoke(int $tokenId, int $userId): bool` | Revoke with ownership check |
| `listForUser(int $userId): array` | List tokens; hashes never included |

Token security model:
- Raw token = `bin2hex(random_bytes(32))` — 64 hex chars, 256 bits of entropy.
- Only `hash('sha256', $raw)` is stored. The raw token is **shown once and never recoverable**.
- Lookup is via unique index on `token_hash` — single-row result guaranteed.
- `last_used_at` is updated on every successful verification.
- A token is valid when: `revoked_at IS NULL` AND (`expires_at IS NULL` OR `expires_at > now`).

---

## Token Permissions

A token currently **inherits the full permission set of its owner** (via group membership).

Resolution path:
```
api_tokens.user_id → user_groups.user_id → group_permissions.group_id → permissions.name
```

This is intentional and future-proof: when `token_permissions` is added, `TokenService::verify()`
will intersect the user's permissions with the token's explicit subset — without changing any
middleware or controller code.

---

## Gate (Authorization)

**Class:** `App\Core\Gate`

| Method | Description |
|---|---|
| `permissionsForUser(int $userId): array` | All permission names via groups |
| `userCan(int $userId, string $permission): bool` | Single user permission check |
| `can(array $principal, string $permission): bool` | Check against a principal envelope |

Authorization decisions are centralized here. Neither session auth nor token auth
contains any permission logic — they build the principal and defer to `Gate`.

---

## Middleware

### `SessionAuth`

Requires a valid session. Writes `principal` to container.  
**401 JSON** if not authenticated. Use for AJAX / JSON API routes.

### `WebAuth`

Identical principal-building logic to `SessionAuth`, but designed for **browser (HTML) routes**.  
**302 redirect to `/auth/login`** if not authenticated. Use for routes that render pages.

```php
$router->get('/', 'NetMon\Controllers\HomeController@index', ['WebAuth']);
```

### `TokenAuth`

Requires `Authorization: Bearer <token>` header. Writes `principal` to container.  
401 if header absent or token invalid/expired/revoked.

### `RequirePermission`

Requires the principal to hold a named permission.  
Must run **after** `SessionAuth` or `TokenAuth`.  
401 JSON if no principal. 403 JSON if principal lacks the permission.

**Use for:** AJAX / JSON API routes.

**Route registration syntax:**
```php
$router->get('/path', 'Controller@method', ['SessionAuth', 'RequirePermission:users.view']);
```

The colon syntax `'ClassName:arg'` is parsed by the Router. The `arg` is passed as the
second constructor parameter to the middleware instance.

### `WebPermission`

Requires the principal to hold a named permission.  
Must run **after** `WebAuth`.  
Redirects to `/auth/login` (302) if no principal is present. Renders an HTML 403 page if principal lacks the permission.

**Use for:** Browser HTML routes (as opposed to AJAX/API routes). This is the HTML-friendly counterpart to `RequirePermission`.

**Route registration syntax:**
```php
$router->get('/admin', 'Controllers\Admin\AdminController@index', ['WebAuth', 'WebPermission:admin']);
```

---

## HTTP Endpoints

### `GET /auth/login` — public

Renders the browser login page (`app/Views/auth/login.php`).

- If the user is already authenticated (valid session), redirects to `/` with 302.
- Otherwise returns 200 with the login form HTML.

The page submits credentials via AJAX (`fetch`) to `POST /auth/login` and redirects to `/` on
success. No server-side form processing — the same JSON API used by other clients is reused.

---

### `POST /auth/login` — public

**Body:** `{ "identity": "username or email", "password": "plaintext" }`

**200:**
```json
{ "user": { "id": 1, "display_name": "Alice Smith", "username": "alice", "email": "...", "is_active": true, "created_at": "...", "updated_at": "..." } }
```
**400:** missing fields  
**401:** invalid credentials (message intentionally vague)

---

### `POST /auth/logout` — public

**200:** `{ "success": true }`

---

### `GET /auth/me` — requires `SessionAuth`

**200:** `{ "user": {...} }`  
**401:** not authenticated

---

### `GET /api/tokens` — requires `SessionAuth`

Returns the current user's tokens. Hashes never included.

**200:** `{ "tokens": [ { "id": 1, "name": "...", "last_used_at": null, "expires_at": null, "revoked_at": null, "created_at": "..." }, ... ] }`

---

### `POST /api/tokens` — requires `SessionAuth`

**Body:** `{ "name": "CI deploy key", "expires_at": "2025-12-31 00:00:00" }`  
`expires_at` is optional. If provided, must be a future datetime.

**201:**
```json
{
  "token": "<raw 64-char hex — shown ONCE, store immediately>",
  "record": { "id": 2, "name": "CI deploy key", ... }
}
```
**400:** invalid input

---

### `DELETE /api/tokens/{id}` — requires `SessionAuth`

Revoke one of the current user's tokens. Ownership enforced.

**200:** `{ "success": true }`  
**404:** not found or already revoked

---

## Router Middleware Stack

The Router accepts a middleware array per route:
```php
$router->post('/path', 'Controller@action', ['MiddlewareA', 'MiddlewareB:arg']);
```

Middleware is resolved from `App\Middleware\{ClassName}` and instantiated as:
```php
new ClassName($container, $arg)   // $arg is null when no colon suffix
```

The chain runs left-to-right. Each middleware calls `$next($params)` to continue.
Short-circuiting (e.g., 401 without calling `$next`) terminates the chain.

---

## How Session and Token Auth Coexist

- They are **separate middleware** applied to separate route groups.
- A route either requires `SessionAuth` or `TokenAuth`, not both.
- Both write the same `principal` envelope to the container.
- Downstream middleware (`RequirePermission`) and controllers are completely auth-method-agnostic.
- If both were applied to the same route, the second would overwrite the first in the container.

Typical pattern:
- Browser/AJAX routes → `SessionAuth`
- API routes (headless clients) → `TokenAuth`

---

## Adding a Future Auth Provider

1. Create `app/Auth/LdapAuthProvider.php` implementing `AuthProviderInterface`.
2. In `public/index.php`, change `new LocalAuthProvider(...)` to `new LdapAuthProvider(...)`.
3. No other files change.

The `AuthService`, session middleware, and all downstream code are provider-agnostic.

---

## Configuration

`config/auth.php`:
```php
return [
    'provider' => 'local',
    'session'  => [
        'name'     => 'netmon_session',
        'lifetime' => 7200,
        'secure'   => false,   // true in production (HTTPS)
    ],
];
```

---

## Views

| File | Route | Description |
|---|---|---|
| `app/Views/auth/login.php` | `GET /auth/login` | Minimal Bootstrap 5 login form. Submits via AJAX to `POST /auth/login`. Redirects to `/` on success. |
| `app/Views/profile/index.php` | `GET /profile` | Profile page — account summary, notification preferences, API token management. Reached from topbar user menu. |
| `app/Views/admin/index.php` | `GET /admin` | Admin landing page — stat cards and quick-nav links. Requires `admin` permission. |
| `app/Views/admin/users.php` | `GET /admin/users` | Users list (DataTable). Requires `admin` permission. |
| `app/Views/admin/groups.php` | `GET /admin/groups` | Groups list with member/permission counts (DataTable). Requires `admin` permission. |
| `app/Views/admin/permissions.php` | `GET /admin/permissions` | Permissions list with group count (DataTable). Requires `admin` permission. |

### Profile page and API token management UI

API tokens are managed in the **Profile page** (`/profile → #api-tokens` card). The card is JavaScript-driven:

- On load: calls `GET /api/tokens` to list current tokens
- Create: `POST /api/tokens` with JSON body — raw token shown once in a reveal alert, then hidden
- Revoke: `DELETE /api/tokens/{id}` with a confirm dialog

All token API endpoints use `SessionAuth`. The `/api/tokens/*` routes are not accessible via API token authentication — this prevents bootstrap problems where a token could revoke itself.

**Controller:** `app/Controllers/ProfileController.php`

---

## Deferred Improvements

| Item | Notes |
|---|---|
| `token_permissions` table | Will allow scoping a token to a subset of the user's permissions. Hook point: `TokenService::verify()` — intersect `permissionsForUser` with token-specific rows. No schema change needed for the other tables. |
| User management endpoints | Schema and hashing are ready. A `UserController` with create/edit/delete/list actions is a separate task. |
| Password reset | No reset token flow or email delivery implemented. |
| `session.secure` | Must be `true` when serving over HTTPS. Currently `false` in `config/auth.php` for local development. |
| Rate limiting on `/auth/login` | No brute-force protection. |
| Token rotation | Tokens are static after creation. Generating a replacement and revoking the old one is a manual process. |
| Polished login UI | The current `login.php` is intentionally minimal. The dark-themed reference at `docs/reference/signin-signup/` can be adapted into a proper design pass later. |
