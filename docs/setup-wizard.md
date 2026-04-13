# Setup Wizard Blueprint

## Overview

The web-based setup wizard provides a guided, browser-based first-run installation experience. It is the recommended path for non-technical users, managed hosting environments, and any deployment where running CLI commands is inconvenient.

The wizard is implemented as a **reusable module** (`app/Modules/Setup/`). It contains no NetMon-specific logic. Application identity, default values, and the list of seed files to run are injected via configuration — the module itself remains portable.

For the backend phase reference and API contracts, see [install.md](install.md).  
For the code architecture and class placement, see [installer-architecture.md](installer-architecture.md).

---

## Core Design Principles

- **One page, multiple steps.** The wizard shell loads once at `GET /setup`. All step transitions happen via AJAX — no full page reloads.
- **Linear flow.** Steps must be completed in order. Skipping is not allowed. Going back is allowed.
- **Server-side authority.** All validation lives on the server. Client-side validation is progressive enhancement only.
- **No dependencies on the app theme.** The wizard renders before LESS files are compiled; it uses its own minimal self-contained styles.
- **Idempotent execution.** The final install step can be retried safely if it fails partway through.
- **Single-use only.** The wizard is permanently inaccessible after installation completes.

---

## Entry Conditions and Lock Mechanism

### Allowing entry

The wizard is accessible only when **both** signals confirm the app is not installed:

| Signal | Not installed | Installed |
|---|---|---|
| `/storage/installed.lock` | file absent | file present |
| `.env` flag | `APP_INSTALLED=false` (or file absent) | `APP_INSTALLED=true` |

Both must be absent/false for the wizard to be reachable. Defense in depth: if either is present, the boot guard blocks access.

### Preventing re-entry after installation

The boot guard runs at the very top of `public/index.php`, before the Container or Router are initialized. It handles four cases:

| State | Incoming request | Action |
|---|---|---|
| Not installed | Any non-`/setup` URI | `302` redirect to `/setup` |
| Not installed | `/setup/*` URI | Allow — wizard renders normally |
| Installed | Any non-`/setup` URI | Normal app boot continues |
| Installed | `/setup/*` URI | `403 Forbidden` — hard block |

The lock file write and `.env` flag update happen as the **last step** of installation. Until both are written, the wizard remains re-entrant, which means a failed install can be retried without manual cleanup.

### Manually resetting the lock (reinstall)

To re-run the wizard after a completed install:

1. Delete `/storage/installed.lock`
2. Edit `.env`: set `APP_INSTALLED=false`
3. Optionally wipe the database

Both signals must be cleared. Clearing only one is not sufficient — the boot guard treats them independently.

---

## Wizard Shell Structure

The wizard renders a single HTML page (`Views/wizard.php`) at `GET /setup`. It contains:

- A `<head>` with self-contained CSS (Bootstrap 5 CDN or bundled; no dependency on compiled LESS)
- A progress indicator showing all steps and which are complete/active/pending
- A `<main>` content area where the current step's panel is shown
- A `<footer>` with Back / Continue / Install Now buttons (contextually shown/hidden)
- A hidden CSRF token field injected into the page from the PHP session
- A `<script>` block containing the wizard's JavaScript state machine

All step panels exist in the DOM from the start (hidden), or are injected dynamically. Either approach is acceptable; the simpler "all panels in DOM, hide/show" pattern is recommended for the initial implementation.

---

## Step Flow

```
[1] Welcome
      ↓ (auto-advance or button)
[2] Requirements
      ↓ pass / ↑ retry on failure
[3] Database
      ↓ connection tested / ↑ back
[4] Application
      ↓ saved / ↑ back
[5] Administrator
      ↓ validated / ↑ back
[6] Summary
      ↓ "Install Now"
[7] Installing... (progress display)
      ↓ success
[8] Complete
```

Steps 1–6 are user-facing input/confirmation screens.  
Step 7 is a non-interactive progress screen.  
Step 8 is a terminal screen; no navigation back from here.

---

## Screen Specifications

### Screen 1 — Welcome

**Purpose:** Orient the user. No input required.

**Layout:**
- App logo / name (injected from config — generic placeholder if not set)
- Heading: "Welcome to [App Name] Setup"
- Body: 1–2 sentences explaining the wizard
- Checklist summary of what the wizard will do (bullets, not a form):
  - Check system requirements
  - Configure database
  - Set application preferences
  - Create administrator account
  - Finalize installation
- Single button: **"Start Installation"**

**Behavior:**
- Clicking "Start Installation" advances to Screen 2 and immediately fires `POST /setup/check`
- No Back button on this screen

**Generic vs NetMon-specific:**
- Generic: layout, checklist, button behavior
- NetMon-specific: app name, logo (injected via config — not hardcoded in the module)

---

### Screen 2 — Requirements

**Purpose:** Confirm the server meets prerequisites before any changes are made.

**Layout:**
- Heading: "System Requirements"
- A check list rendered from the AJAX response:
  - Each row: icon (✓ / ✗ / ⚠) + label + optional detail badge
  - Required failures render in red with a `fix` hint below the row
  - Optional items render in amber if missing
- On all pass: success message, "Continue" button enabled
- On any required failure: error state, "Continue" button disabled, "Retry" link shown

**Triggered by:** `POST /setup/check` (fires on entering this step)

**Retry behavior:**
- "Retry" re-fires `POST /setup/check`
- The user fixes the server issue externally and retries in the same browser window
- The wizard does not need a full reload — the AJAX result replaces the check list

**Check items displayed:**

| Check | Type | Fix hint on failure |
|---|---|---|
| PHP ≥ 8.1 | Required | "Current: X.Y. Upgrade PHP on your server." |
| ext-pdo | Required | "Enable the pdo extension in php.ini" |
| ext-pdo_sqlite | Required | "Enable the pdo_sqlite extension in php.ini" |
| ext-json | Required | "Enable the json extension in php.ini" |
| ext-openssl | Required | "Enable the openssl extension in php.ini" |
| ext-mbstring | Required | "Enable the mbstring extension in php.ini" |
| ext-pdo_mysql | Optional | "Needed for MySQL/MariaDB support" |
| ext-ldap | Optional | "Needed for LDAP authentication (future)" |
| `/storage/` writable | Required | "Run: chmod 775 storage/" |
| `/data/` writable | Required | "Run: chmod 775 data/ (or mkdir data && chmod 775 data/)" |
| `/config/` writable | Required | "Run: chmod 775 config/" |

**Generic vs NetMon-specific:**
- Generic: all check items, all retry behavior, the response renderer
- NetMon-specific: none — the required/optional extension list comes from kernel config

---

### Screen 3 — Database Configuration

**Purpose:** Select the database engine and verify the connection.

**Layout:**
- Heading: "Database Configuration"
- Driver selector: radio buttons or toggle
  - **SQLite** (default, pre-selected)
  - **MySQL / MariaDB** (future — render as disabled with "coming soon" label initially)
- SQLite panel (shown when SQLite selected):
  - Read-only display of the database file path: `/data/app.db`
  - Note: "No credentials required. The file will be created automatically."
- MySQL panel (hidden when SQLite selected, shown when MySQL selected):
  - Host (text, default: `127.0.0.1`)
  - Port (number, default: `3306`)
  - Database name (text)
  - Username (text)
  - Password (password input)
- **"Test Connection"** button — fires `POST /setup/db`
- Connection result inline: green success badge or red error message
- **"Continue"** button — enabled only after a successful connection test

**Behavior:**
- Driver switch immediately shows/hides the appropriate panel (JS only, no server call)
- Connection test result is shown inline without moving to the next step
- Changing any field after a successful test resets the "tested" state (Continue re-disabled until re-tested)
- Back button returns to Screen 2

**Error handling:**
- Connection failure: show sanitized error message inline (not in an alert/modal — in the panel itself)
- Never display raw PDO exception strings; show a friendly message + the sanitized driver error

**Generic vs NetMon-specific:**
- Generic: all of this screen, driver toggle logic, connection test
- NetMon-specific: none. The MySQL database name default (`netmon`) comes from config if provided, but is not hardcoded in the module.

---

### Screen 4 — Application Configuration

**Purpose:** Set application identity and environment preferences.

**Layout:**
- Heading: "Application Settings"
- Form fields:

| Field | Type | Default | Notes |
|---|---|---|---|
| Application name | text | from `config/app.php` `name` key | e.g. `NetMon` |
| Base URL | text | inferred from `$_SERVER['HTTP_HOST']` | e.g. `https://example.com` |
| Environment | select | `production` | Options: `development`, `production` |
| Debug mode | checkbox | off | Shown only if Environment = `development`; auto-disabled in production |

- Inline hint under Base URL: "Include scheme (https://). Trailing slash is optional."
- **"Continue"** button submits → `POST /setup/config`
- Back button returns to Screen 3

**Behavior:**
- If Environment changes to `production`, Debug mode is unchecked and its field hidden (JS)
- "Continue" submits the form; if validation passes, the wizard advances to Screen 5
- If validation fails, error messages appear inline below each invalid field
- On success, `.env` and `config/local.php` are written at this point

**Validation rules (server-side):**

| Field | Rules |
|---|---|
| Application name | Required. 1–100 characters. |
| Base URL | Required. Must start with `http://` or `https://`. Trailing slash stripped. |
| Environment | Required. Must be `development` or `production`. |
| Debug mode | Boolean. Forced `false` when environment = `production`. |

**Generic vs NetMon-specific:**
- Generic: all form logic, validation, config write behavior
- NetMon-specific: the default value for "Application name" (`NetMon`) is read from `config/app.php`; the module does not hardcode it

---

### Screen 5 — Administrator Account

**Purpose:** Define the first user account, which will have full administrative access.

**Layout:**
- Heading: "Create Administrator Account"
- Subheading: "This account will have full access to [App Name]."
- Form fields:

| Field | Type | Notes |
|---|---|---|
| Full name | text | Display name |
| Username | text | Login identifier |
| Email address | email | |
| Password | password | Strength indicator (visual only, no blocking) |
| Confirm password | password | |

- **"Continue"** button submits → `POST /setup/admin`
- Back button returns to Screen 4

**Behavior:**
- Password strength indicator updates as the user types (JS only — informational, does not block submission)
- Confirm password mismatch shown inline as the user leaves the field (JS, but server also validates)
- "Continue" fires the AJAX request; if validation passes, advances to Screen 6
- If validation fails, each error appears inline below the relevant field

**Validation rules (server-side):**

| Field | Rules |
|---|---|
| Full name | Required. 2–100 characters. |
| Username | Required. 3–50 characters. Pattern: `[a-zA-Z0-9_]+`. Must be unique in `users` table (checked live via DB at this point — migrations have not run yet; this check is deferred to the install step). |
| Email | Required. Valid email format (`filter_var` FILTER_VALIDATE_EMAIL). |
| Password | Required. ≥ 8 characters. |
| Confirm password | Required. Must match password. |

> **Note on username uniqueness:** Because migrations run during Screen 7, the `users` table may not exist yet when Screen 5 is submitted. The uniqueness check is therefore deferred — it runs during Phase 9 inside `POST /setup/install`. If the username conflicts with a pre-existing record (e.g., re-run after partial install), the install step returns an error and the user is redirected back to Screen 5.

**Data handling:**
- Validated field values (except password) are stored in `$_SESSION['__setup']['admin']`
- The raw password is **never stored in the session**
- The raw password is re-submitted in the `POST /setup/install` body at execution time
- The password is hashed immediately on receipt during Phase 9 and discarded

**Generic vs NetMon-specific:**
- Generic: all form logic, validation, session storage behavior
- NetMon-specific: none. The admin group name (`admin`) is a config value, not hardcoded.

---

### Screen 6 — Summary

**Purpose:** Show a complete, human-readable summary of what will be installed before any irreversible changes.

**Layout:**
- Heading: "Ready to Install"
- Summary card — Database:
  - Driver: SQLite / MySQL
  - SQLite: file path
  - MySQL: host, port, database name (no password shown)
- Summary card — Application:
  - Name, URL, environment
- Summary card — Administrator:
  - Full name, username, email (no password shown)
- Summary card — Installation steps:
  - Ordered list of what will happen (migrations count, seeds, lock file creation)
- Two buttons:
  - **"Back"** — returns to Screen 5
  - **"Install Now"** — fires `POST /setup/install`, advances to Screen 7

**Behavior:**
- No inputs on this screen — read-only summary only
- "Install Now" is a single-click action; the button disables immediately after click to prevent double-submit
- Once "Install Now" is clicked, Back is no longer available

**Generic vs NetMon-specific:**
- Generic: layout, summary cards structure, button behavior
- NetMon-specific: the specific seed file names listed in the "Installation steps" card come from config

---

### Screen 7 — Installing

**Purpose:** Display real-time (or final) installation progress. Prevent user interaction during execution.

**Layout:**
- Heading: "Installing..."
- Progress list — each row shows a step with a spinner → checkmark (or ✗ on failure):
  - Running migrations
  - Running seeds
  - Creating administrator account
  - Writing install lock
- No buttons while in progress
- On completion: auto-advance to Screen 8
- On failure: show which step failed, error message, and a **"Retry"** button

**Triggered by:** `POST /setup/install`

**Response handling (synchronous MVP):**
- Single AJAX call to `POST /setup/install`
- On success: parse the `steps` array from the response, animate each row to ✓, then advance
- On failure: show error on the failed step row, show Retry button

**Retry behavior on failure:**
- All phases are idempotent: migrations use the `migrations` table to skip already-applied files; seeds check for existing records; admin creation can check for existing username
- Retrying re-fires `POST /setup/install` with the same session data plus re-submitted password
- The password must be re-entered before retry (see Screen 5 re-submission note below)

**Password re-submission on retry:**
- If Screen 7 fails and the user retries, the raw password must be available
- Two options (decide at implementation time):
  - A: Present a minimal "re-enter your password to retry" modal before re-firing install
  - B: Keep a non-persisted in-memory JS variable holding the password for the duration of the wizard session (cleared on page close or success)
  - Recommended: Option A — cleaner, no sensitive data lingering in JS

**Generic vs NetMon-specific:**
- Generic: all of this screen, retry logic, progress display
- NetMon-specific: none

---

### Screen 8 — Complete

**Purpose:** Confirm installation succeeded. Terminate the wizard session.

**Layout:**
- Large success icon
- Heading: "Installation Complete"
- Body: "NetMon has been installed successfully." (app name from config)
- Admin reminder: "You can log in with username: **[username]**"
- Single button: **"Go to Login"** → navigates to `/auth/login` (full page navigation, not AJAX)

**Behavior:**
- PHP session `__setup` is destroyed on server side when `POST /setup/install` succeeds
- Navigating back to `/setup` after this screen returns `403 Forbidden` (boot guard)
- The "Go to Login" button performs a standard `window.location` redirect, not AJAX

**Generic vs NetMon-specific:**
- Generic: layout, session destruction, redirect
- NetMon-specific: app name in the success message; login URL (`/auth/login` is defined in routes — the module should receive this as a config value, not hardcode it)

---

## Navigation and State Model

### Forward navigation

A step is "unlocked" only when the preceding step has received `ok: true` from its server call. The progress indicator reflects this:

| State | Indicator style |
|---|---|
| Completed | Filled circle with ✓, step label normal weight |
| Active (current) | Filled circle with step number, bold label |
| Pending | Outline circle with step number, muted label |
| Failed | Filled circle with ✗, red label |

### Back navigation

- Back is available on Screens 3–6
- Going back does **not** undo server-side writes (e.g., if the user wrote config at Screen 4 and goes back, `local.php` still exists)
- Going back re-displays the previous step's form pre-filled from session data
- The user can edit and re-submit; the session values are updated and the config files are overwritten

### Browser refresh behavior

- Refreshing the browser at any step before Screen 7 restarts the wizard at Screen 2 (or whichever step the session's `step` value indicates was last completed)
- Refreshing during Screen 7 (mid-install) is a degraded case:
  - If the install completed before the refresh, the lock is already written → boot guard returns 403
  - If the install was in progress and failed before the lock, the wizard re-enters at the summary (Screen 6) and the user can retry
- Refreshing after Screen 8 returns 403 (expected)

### Session expiry

If the PHP session expires mid-wizard (default session lifetime):
- The wizard detects this on the next AJAX request (missing CSRF token → 403 response)
- The frontend receives a 403 and redirects to `/setup` with a "Your session expired. Please start again." message
- This restarts the wizard from Screen 1; no data is lost on the server (only session data is gone)

---

## Validation Behavior

### Rules

1. All validation is performed **server-side**.
2. Client-side validation (inline messages before submit, password match check) is **progressive enhancement** — it improves UX but the server never trusts client pre-validation.
3. Required fields that are empty return `errors.{field} = "This field is required."` — generic, not hardcoded per-field.
4. If a validation error occurs, the response is:
   ```json
   {
     "ok": false,
     "errors": {
       "field_name": "Human-readable error message."
     }
   }
   ```
5. The frontend maps `errors` keys to DOM field IDs and renders messages below each field.

### Error display

- Inline, below the input — not a banner or modal
- Red border on invalid inputs
- Error text removed as soon as the field is re-focused (JS) and definitively on next successful submit
- If the server returns an unexpected error (500), a top-level banner is shown: "An unexpected error occurred. Please try again."

### Blocking vs non-blocking

| Check | Blocking? |
|---|---|
| Required field empty | Yes — Continue button stays disabled |
| Invalid format (email, URL) | Yes |
| Password too short | Yes |
| Password mismatch | Yes |
| Optional extension missing | No — shown in amber, wizard continues |
| Connection test not yet run | Yes — Continue disabled until test passes |

---

## Generic vs NetMon-Specific Breakdown

This table covers every element of the wizard and explicitly marks what belongs to the reusable module vs. what is NetMon-specific.

| Element | Generic (`app/Modules/Setup/`) | NetMon-specific (`app/NetMon/` or config) |
|---|---|---|
| Wizard shell HTML structure | Yes | — |
| Progress indicator component | Yes | — |
| Step navigation JS state machine | Yes | — |
| CSRF token generation and validation | Yes | — |
| Boot guard logic | Yes (kernel `InstallLock`) | — |
| Screen 1: layout, button | Yes | App name and logo (from `config/app.php`) |
| Screen 2: env/dir check list rendering | Yes | — |
| Screen 2: required extensions list | Yes (from kernel `EnvironmentChecker` config) | — |
| Screen 3: driver toggle UI | Yes | MySQL DB name default (from config, optional) |
| Screen 3: connection test | Yes | — |
| Screen 4: form layout | Yes | Default app name (`NetMon` from `config/app.php`) |
| Screen 4: `.env` write | Yes (kernel `ConfigWriter`) | Key names (`APP_NAME` etc.) are generic |
| Screen 4: `config/local.php` write | Yes (kernel `ConfigWriter`) | — |
| Screen 5: admin form | Yes | Admin group name (`admin` from config) |
| Screen 5: password hashing | Yes (uses `password_hash`) | — |
| Screen 6: summary layout | Yes | Seed file names listed (from config list) |
| Screen 7: progress display | Yes | — |
| Screen 7: migration execution | Yes (calls `MigrationRunner`) | Migration files are NetMon's |
| Screen 7: seed execution | Yes (calls `SetupService::runSeeds`) | Seed class list passed from NetMon config |
| Screen 7: admin user creation | Yes (calls `UserRepository`) | Admin group name from config |
| Screen 7: install lock write | Yes (kernel `InstallLock`) | — |
| Screen 8: success layout | Yes | App name; login URL from config |
| Session state management | Yes | — |
| Error response shape `{ok, errors}` | Yes | — |
| Retry logic | Yes | — |
| Wizard self-contained CSS | Yes | May incorporate NetMon brand color via a CSS variable at most |

**Summary:** The `app/Modules/Setup/` module is 100% generic. All NetMon-specific values (app name, admin group, seed list, login URL) are injected via `config/app.php` or a dedicated `config/setup.php`. The module reads these; it does not hardcode them.

---

## Reusability for Future Applications

When a future application is built on this same foundation, it reuses the entire wizard by:

1. Setting `config/app.php` with its own `name`, `url`, etc.
2. Creating a `config/setup.php` (or extending `config/app.php`) to specify:
   - `seeds` — list of seed class names to run during installation
   - `admin_group` — name of the group to assign the first user to
   - `post_install_redirect` — URL to send the user after setup completes
3. Providing its own migration files under `database/migrations/`

The module itself is untouched. No forking required.

---

## Wizard Self-Contained Styles

The wizard **must not** depend on the compiled LESS output in `public/assets/css/`. At install time:
- The LESS build may not have run
- `public/assets/css/app.css` may not exist

Instead, the wizard's `wizard.php` view either:
- References Bootstrap 5 from CDN (acceptable for first-run; internet access is assumed for a server being set up), **or**
- Bundles a minimal extracted Bootstrap CSS inline or as a separate small file at `public/assets/css/wizard.css`

The CDN approach is simpler and recommended for the initial implementation. The bundled approach is preferred for air-gapped environments and can be added later.

The wizard uses Bootstrap 5's built-in components:
- `progress` (step indicator at the top)
- `card` (each step panel)
- `form-control`, `form-label`, `invalid-feedback`
- `btn`, `btn-primary`, `btn-outline-secondary`
- `alert` (connection result, error banner)
- Bootstrap Icons for check/fail/spinner glyphs

No custom CSS beyond one or two utility tweaks is expected.

---

## Security Considerations

| Risk | Mitigation |
|---|---|
| Wizard accessible after install | Boot guard: `InstallLock::isInstalled()` checked before every request; `/setup/*` returns 403 |
| CSRF on setup POST endpoints | One-time token in `$_SESSION['__setup']['token']`; validated server-side; rotated after final install step |
| Password in session | Raw password never stored in session or logged; re-submitted at install time; hashed immediately on receipt |
| DB credentials in logs | MySQL connection errors sanitized before returning to client; never logged at DEBUG level in production |
| Stack traces exposed | `ErrorHandler` checks `APP_DEBUG`; even when `.env` hasn't been written yet (pre-Phase 5), debug defaults to `false` |
| Parallel install sessions | Race condition on lock write is low risk for single-server deployments; acceptable for MVP |
| Retry loop abuse | Lock prevents re-entry; even if lock doesn't exist, all operations are idempotent — no harm from repeated execution |
| Session fixation | Session ID is regenerated at wizard entry (`session_regenerate_id(true)`) |

---

## Deferred Implementation Details

| Detail | Notes |
|---|---|
| Password re-entry on retry | Decide between Option A (modal) and Option B (JS memory) at implementation time; Option A recommended |
| Streaming progress (Screen 7) | Synchronous single-response is MVP; SSE or chunked streaming is a future enhancement |
| MySQL support (Screen 3) | Driver toggle renders MySQL fields but they are disabled/marked "coming soon" until `MySQLDriver` is implemented |
| Air-gapped Bootstrap bundle | CDN is acceptable for initial implementation; bundle for offline environments later |
| `config/setup.php` definition | The specific keys (`seeds`, `admin_group`, `post_install_redirect`) need to be formalized when implementation begins |
| Wizard CSS brand variables | One `--color-primary` CSS variable override is sufficient for NetMon branding; no full theme needed |
| LDAP/OAuth setup steps | Deferred future wizard steps; placeholder "additional configuration" screen reserved but empty |
| Multi-language/i18n | Not planned; all strings are English; extraction to a strings file is a future concern |

---

## See Also

- [install.md](install.md) — Installation phase reference, CLI flow, SQLite vs MySQL paths
- [installer-architecture.md](installer-architecture.md) — Code architecture: class locations, layer assignments, boot guard, implementation order
- [architecture.md](architecture.md) — Three-layer codebase architecture
- [migrations.md](migrations.md) — Migration system
- [auth.md](auth.md) — Authentication system (admin account creation relies on this)
