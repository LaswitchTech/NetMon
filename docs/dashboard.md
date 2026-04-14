# Dashboard Shell

## Overview

`GET /` renders the main authenticated dashboard. It is the entry point for the browser-based UI after login.

Authentication is enforced by the `WebAuth` middleware — unauthenticated requests are redirected to `/auth/login`.

---

## View Structure

```
app/Views/
    layouts/
        app.php          ← Reusable shell: topbar, sidebar, $content slot
    dashboard/
        index.php        ← Dashboard content fragment (rendered into the shell)
    auth/
        login.php        ← Login page (standalone, no shell)
```

### Shell layout (`app/Views/layouts/app.php`)

The layout file renders the full page chrome:

| Region | Description |
|--------|-------------|
| Topbar | Fixed, 56 px. App name (left), username + dropdown with Sign Out (right). |
| Sidebar | 220 px, fixed. Navigation links. Hidden on screens narrower than 768 px. |
| Content | `<?= $content ?>` slot — receives the captured output of the page-specific view. |

**PHP variables expected by the layout:**

| Variable | Type | Description |
|----------|------|-------------|
| `$content` | string | HTML of the page-specific fragment (from `ob_get_clean()`) |
| `$user` | array | Safe user record from the principal (`id`, `display_name`, `username`, `email`, …) |
| `$displayName` | string | `display_name` if non-empty, otherwise `username`. Computed in the controller before `ob_start()` so it is also available inside the content view. |
| `$appName` | string | Application name from `config/app.php` |
| `$pageTitle` | string | Page title for `<title>` and active sidebar state |

---

## Rendering Pattern

Controllers that render HTML pages follow this two-step pattern:

```php
// Step 1 — capture the page-specific content
ob_start();
require $viewsPath . '/dashboard/index.php';   // or any other page view
$content = ob_get_clean();

// Step 2 — render the full shell with the content injected
http_response_code(200);
header('Content-Type: text/html; charset=utf-8');
require $viewsPath . '/layouts/app.php';
```

Variables set before `ob_start()` are in scope for both the content view and the layout because PHP's `require` inherits the current variable scope.

---

## Adding a New Page

To add a module page (e.g. `/devices`):

1. Create `app/Views/devices/index.php` — content fragment only (no `<html>` wrapper).
2. Create or update the controller:

```php
public function index(array $params = []): void
{
    $principal = $this->container->get('principal');
    $config    = $this->container->get('config');

    $user        = $principal['user'];
    $permissions = $principal['permissions'];
    $appName     = $config['name'];
    $pageTitle   = 'Devices';          // controls <title> and sidebar active state

    // Must be set before ob_start() so content views can use it
    $displayName = ($user['display_name'] ?? '') !== ''
        ? $user['display_name']
        : $user['username'];

    $viewsPath = __DIR__ . '/../../Views';  // adjust relative to controller location

    ob_start();
    require $viewsPath . '/devices/index.php';
    $content = ob_get_clean();

    http_response_code(200);
    header('Content-Type: text/html; charset=utf-8');
    require $viewsPath . '/layouts/app.php';
}
```

3. Register the route in `routes/web.php` with `WebAuth`:

```php
$router->get('/devices', 'Modules\Devices\Controllers\DeviceController@index', ['WebAuth']);
```

4. Add the link to the sidebar in `app/Views/layouts/app.php`.

---

## Sidebar Navigation

Current sidebar links (defined in `app/Views/layouts/app.php`):

| Link | Route | State |
|------|-------|-------|
| Dashboard | `/` | Active |
| Network | `#` | Disabled (module not yet built) |
| Devices | `/devices` | Active |
| Alerts | `#` | Disabled (module not yet built) |

The active link is determined by comparing `$pageTitle` to the link label in the layout template.

---

## Sign Out

The topbar dropdown contains a **Sign Out** button. It POSTs to `/auth/logout` via `fetch()`, then redirects to `/auth/login`. The `POST /auth/logout` endpoint is public (no middleware required) and destroys the session.

---

## Authentication Guard

`GET /` uses the `WebAuth` middleware (not `SessionAuth`):

| Middleware | Auth failure response | Used for |
|------------|----------------------|----------|
| `WebAuth` | 302 redirect → `/auth/login` | HTML browser routes |
| `SessionAuth` | 401 JSON | AJAX / API routes |

See [auth.md](auth.md) for full middleware documentation.
