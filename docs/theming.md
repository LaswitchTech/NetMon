# NetMon Theming System

## Overview

NetMon uses a LESS-based theme system that compiles to a single `public/assets/css/app.css`. Themes are defined as CSS custom property blocks so a single compiled file supports dark mode, light mode, and any future themes at runtime — no separate CSS file per theme.

---

## Build Workflow

### Prerequisites

Node.js and npm must be installed on the developer's machine. The `less` compiler
is the only npm dependency and is installed locally — it never pollutes the global
environment.

```
node_modules/   ← .gitignored, never committed
```

### Compiling CSS

The preferred way to compile is through the build script, which lives alongside
the other project scripts:

```bash
bash scripts/build-css.sh           # compile once
bash scripts/build-css.sh --watch   # watch mode — recompiles on every LESS save
```

The script auto-runs `npm install` on the first use if `node_modules/` is missing,
so no manual setup step is required after a fresh clone.

Alternatively, use npm directly:

```bash
npm install              # first time only (or after node_modules/ is deleted)
npm run build:css        # compile once
npm run watch:css        # watch mode
```

Source entry point: `public/assets/less/app.less`
Output: `public/assets/css/app.css`

### When to recompile

Recompile after **any change** to a `.less` file under `public/assets/less/`.

The most common cases:
- Editing a theme token in `themes/dark.less` or `themes/light.less`
- Modifying a component style in `components/`
- Adding a new `@import` to `app.less`

Use watch mode during active theme or component work to recompile automatically.

### Committing the compiled output

`public/assets/css/app.css` **is committed to the repository**.

Reason: this is a PHP application deployed to web servers that do not run Node.
Committing the compiled CSS means the app works out of the box on any server
without a build step at deploy time.

Rule: always commit an updated `app.css` alongside any `.less` change in the
same commit. The LESS sources and the compiled output must stay in sync.

### Future improvements

| Improvement | Notes |
|---|---|
| Minified output | `lessc --clean-css app.less app.min.css` — requires `less-plugin-clean-css` npm package |
| Source maps | `lessc --source-map app.less app.css` — useful for browser devtools during development |
| CI check | A CI job can run `scripts/build-css.sh` and fail if the committed `app.css` differs from the freshly compiled output |

---

## LESS Source Structure

```
public/assets/less/
    app.less                    ← Entry point (imports everything in order)
    variables.less              ← Compile-time LESS @variables (dimensions, z-indexes, timing)
    base.less                   ← Global resets, body, scrollbar, focus ring
    layout.less                 ← App shell structure (sidebar + topbar + content + footer)
    bootstrap-overrides.less    ← Bootstrap component-level CSS variable overrides
    themes/
        dark.less               ← Default theme tokens (:root)
        light.less              ← Light theme overrides ([data-bs-theme="light"])
    components/
        sidebar.less
        topbar.less
        cards.less
        tables.less             ← Bootstrap table + DataTables wrapper theming
        forms.less              ← Form controls + .auth-card
        footer.less
    modules/
        chat.less               ← Chat module (placeholder, fills in when Chat module is built)
```

---

## Two Layers of Variables

### LESS `@variables` — compile-time constants

Defined in `variables.less`. These are baked into the compiled CSS as literal values. Use them for layout dimensions, z-indexes, and timing values that do not change between themes.

```less
@sidebar-width:    280px;
@topbar-height:    72px;
@transition-speed: 0.2s;
@z-sidebar:        1030;
```

### CSS custom properties — runtime theme tokens

Defined in `themes/dark.less` (`:root`) and `themes/light.less` (`[data-bs-theme="light"]`). All component styles consume only `var(--app-*)` tokens — never hardcoded colours. This means component LESS files are completely theme-neutral.

```less
// In any component file — correct
background: var(--app-panel);
color: var(--app-text-muted);
border: 1px solid var(--app-border);

// Never do this in a component file
background: #171a21;
color: #9aa4b2;
```

---

## Dark Theme Tokens (`:root`)

| Token | Value | Usage |
|---|---|---|
| `--app-bg` | `#0f1115` | Page background |
| `--app-panel` | `#171a21` | Card / panel surface |
| `--app-panel-2` | `#1d212b` | Secondary panel (card header, dropdown) |
| `--app-panel-3` | `#252b36` | Tertiary panel (inputs, accordions) |
| `--app-input-bg` | `#11151b` | Form control background |
| `--app-border` | `#2f3744` | Primary border |
| `--app-border-subtle` | `rgba(255,255,255,0.06)` | Subtle divider |
| `--app-text` | `#e9ecef` | Primary text |
| `--app-text-muted` | `#9aa4b2` | Secondary / muted text |
| `--app-primary` | `#0d6efd` | Brand accent / action colour |
| `--app-primary-rgb` | `13, 110, 253` | For `rgba()` usage |
| `--app-nav-active-bg` | `rgba(13,110,253,0.14)` | Active nav item background |
| `--app-nav-active-text` | `#ffffff` | Active nav item text |
| `--app-nav-hover-bg` | `rgba(255,255,255,0.04)` | Nav hover background |
| `--app-sidebar-bg` | `rgba(23,26,33,0.92)` | Sidebar surface (with blur) |
| `--app-topbar-bg` | `rgba(23,26,33,0.78)` | Topbar surface (with blur) |
| `--app-radius` | `0.85rem` | Default border radius |
| `--app-shadow` | `0 0.5rem 1.25rem rgba(0,0,0,0.22)` | Card drop shadow |
| `--app-code-color` | `#9ec5fe` | Inline code text colour |

---

## Light Theme Tokens (`[data-bs-theme="light"]`)

Only tokens that differ from dark are listed below — identical tokens are not repeated.

| Token | Value | Notes |
|---|---|---|
| `--app-bg` | `#f0f2f5` | Page background |
| `--app-panel` | `#ffffff` | Card / panel surface |
| `--app-panel-2` | `#eceff2` | Card headers — distinct from white, light grey-blue |
| `--app-panel-3` | `#e4e8ec` | Disabled fields, code blocks, deepest light accent |
| `--app-input-bg` | `#ffffff` | Form control background |
| `--app-border` | `#ced4da` | Slightly more visible than Bootstrap default `#dee2e6` |
| `--app-border-subtle` | `rgba(0,0,0,0.06)` | Subtle divider |
| `--app-text` | `#212529` | Primary text |
| `--app-text-muted` | `#6c757d` | Secondary / muted text |
| `--app-nav-active-bg` | `rgba(13,110,253,0.10)` | Active nav item background |
| `--app-nav-active-text` | `#0d6efd` | Active nav item text |
| `--app-nav-hover-bg` | `rgba(0,0,0,0.04)` | Nav hover background |
| `--app-sidebar-bg` | `rgba(255,255,255,0.95)` | Sidebar surface |
| `--app-topbar-bg` | `rgba(255,255,255,0.85)` | Topbar surface |
| `--app-shadow` | `0 0.25rem 0.75rem rgba(0,0,0,0.08)` | Card drop shadow |
| `--app-code-color` | `#0d6efd` | Inline code text colour |

---

## Activating a Theme

Themes are activated by the `data-bs-theme` attribute on `<html>`. This is the same attribute Bootstrap 5.3 uses for its own dark mode.

```html
<!-- Dark (default) -->
<html lang="en" data-bs-theme="dark">

<!-- Light -->
<html lang="en" data-bs-theme="light">
```

No CSS class switching is required. JavaScript can toggle the attribute at runtime for a user preference switch:

```js
document.documentElement.setAttribute('data-bs-theme', 'light');
```

---

## Theme Toggle

The application ships a runtime theme toggle in the topbar so users can switch preferences without a page reload or backend round-trip.

### User preferences

Three values are supported, stored in `localStorage` under the key `netmon-theme`:

| Value | Behaviour |
|---|---|
| `'dark'` | Always apply the dark theme |
| `'light'` | Always apply the light theme |
| `'auto'` | Follow the OS / browser `prefers-color-scheme` media query |

`'auto'` is the default when nothing is stored.

### Flash-of-wrong-theme prevention

A synchronous inline `<script>` is placed in `<head>` **before** the CSS `<link>` tags. It reads `localStorage` and sets `data-bs-theme` on `<html>` immediately, so the browser applies the correct theme before any styled content is painted:

```html
<head>
    <script>
    (function(){
        var t = localStorage.getItem('netmon-theme') || 'auto';
        var e = document.documentElement;
        e.setAttribute('data-bs-theme',
            t === 'light' ? 'light' :
            t === 'dark'  ? 'dark'  :
            window.matchMedia('(prefers-color-scheme:light)').matches ? 'light' : 'dark'
        );
    }());
    </script>
    <link rel="stylesheet" href="...">
    ...
</head>
```

This avoids a flash even on slow connections because the attribute is set synchronously before CSS is processed.

### Auto mode — system preference listener

When the stored preference is `'auto'`, a `change` listener on `window.matchMedia('(prefers-color-scheme:light)')` is registered. If the user switches their OS dark/light mode while the page is open, `data-bs-theme` updates in real time without requiring a page reload.

If the stored preference is `'dark'` or `'light'`, the listener is still registered but takes no action — it only triggers a re-apply when the pref is `'auto'`.

### Toggle button

The toggle is a dropdown button in the topbar `.topbar-actions` group. It uses a `<button class="topbar-icon-btn" id="js-theme-toggle">` with three `<button data-netmon-theme="...">` items inside a `.dropdown-menu`:

| Item | Icon | `data-netmon-theme` |
|---|---|---|
| Dark | `bi-moon-stars-fill` | `dark` |
| Light | `bi-sun-fill` | `light` |
| Auto | `bi-circle-half` | `auto` |

The active item gets the Bootstrap `active` class. The toggle button icon updates to reflect the current preference (not the effective theme — `auto` always shows `bi-circle-half` even if the resolved theme is dark).

### JavaScript API

All toggle logic lives in the main `<script>` block of `app/Views/layouts/app.php`. There is no separate JS file for this feature.

Key functions:

```js
// Resolve 'auto' to the effective Bootstrap theme value
resolveEffective(pref)  // → 'dark' | 'light'

// Set data-bs-theme, update icon and active state
applyThemePref(pref)

// Persist to localStorage then apply
setThemePref(pref)
```

`[data-netmon-theme]` click handlers call `setThemePref`. The `matchMedia` change event calls `applyThemePref` (no persistence — just re-evaluates auto).

### No backend persistence (current state)

Theme preference is stored in `localStorage` only. It is not sent to the server and not stored in the database. A future iteration may persist the preference to a user settings row so the preference follows the user across devices.

---

## Bootstrap Integration

Bootstrap component-level CSS variables are overridden in `bootstrap-overrides.less` to bridge Bootstrap's own tokens to the app token system:

```less
.card {
    --bs-card-bg:           var(--app-panel);
    --bs-card-border-color: var(--app-border);
}
```

This means Bootstrap components (cards, dropdowns, modals, pagination, etc.) adapt automatically to theme changes without any additional selectors.

---

## DataTables Integration

DataTables Bootstrap 5 plugin wraps table output in `div.dataTables_wrapper` and adds its own search/pagination/info DOM elements that are not styled by Bootstrap tokens. These are explicitly themed in `components/tables.less` using `var(--app-*)` tokens.

### Vendor assets

Assets are served from local vendor paths (not CDN). Loaded in `app/Views/layouts/app.php`:

```html
<!-- CSS -->
<link rel="stylesheet" href="/assets/vendor/datatables/1.13.8/css/dataTables.bootstrap5.min.css">
<link rel="stylesheet" href="/assets/vendor/datatables-responsive/2.5.0/css/responsive.bootstrap5.min.css">
<link rel="stylesheet" href="/assets/vendor/datatables-buttons/2.4.2/css/buttons.bootstrap5.min.css">

<!-- JS (after Bootstrap + jQuery) -->
<script src="/assets/vendor/jquery/3.7.1/jquery.min.js"></script>
<script src="/assets/vendor/datatables/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="/assets/vendor/datatables/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="/assets/vendor/datatables-responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="/assets/vendor/datatables-responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>
<script src="/assets/vendor/datatables-buttons/2.4.2/js/dataTables.buttons.min.js"></script>
<script src="/assets/vendor/datatables-buttons/2.4.2/js/buttons.bootstrap5.min.js"></script>
```

### Shared initializer (`public/assets/js/datatables-init.js`)

Never call `$(selector).DataTable({...})` directly in page scripts. Always use the shared `NetMon.dt` API:

| Function | DOM | Use when |
|---|---|---|
| `NetMon.dt.init(selector, opts)` | Full layout: search (top-right, ~25% width), Buttons (top-left), length / info / pagination (bottom) | Primary data tables with many rows |
| `NetMon.dt.initCompact(selector, opts)` | `'t'` — table only, no controls | Embedded summary tables with few rows |

Both functions accept an `opts` object that is deep-merged over the defaults, so per-table overrides (`order`, `pageLength`, `columnDefs`, `language.emptyTable`) are straightforward.

`initCompact` explicitly suppresses the Buttons extension (`buttons: null`) and the Responsive extension (`responsive: false`) because compact tables do not have a `B` dom slot and do not need column-collapse behaviour. Passing `pageLength` or `lengthMenu` to a compact table call is harmless but has no effect — there is no length control in `dom: 't'`.

> **DataTables version note:** The project is on **DataTables 1.13.8** (+ Buttons 2.4.2, Responsive 2.5.0). The DT 2.x `layout` / `topStart` / `topEnd` API is **not available**. Always use the `dom` string and `buttons` array for layout configuration. Do not copy DT 2.x documentation examples.

### Injecting actions into the Buttons area

The Buttons extension (`B` slot in the DOM template) renders a `.dt-buttons` container even when no buttons are configured. Use the `initComplete` callback to inject custom HTML into that container:

```js
NetMon.dt.init('#my-table', {
    initComplete: function () {
        $(this.table().container())
            .find('.dt-buttons')
            .prepend('<a href="/things/create" class="btn btn-sm btn-primary me-1">Add Thing</a>');
    },
});
```

Important: the Bootstrap 5 Buttons integration adds `btn-group flex-wrap` to the `.dt-buttons` container and `btn btn-secondary` to any DT-generated buttons. **Do not add a broad `.dt-buttons .btn { ... }` rule in LESS** — it would override Bootstrap variant classes (`btn-primary`, `btn-outline-secondary`, etc.) on custom-injected buttons. If DT export buttons are ever added, scope the theming to `.dt-buttons > button.btn-secondary` instead.

`.dt-buttons:empty { display: none; }` in `components/tables.less` hides the container when nothing is injected, preventing a layout gap on tables with no actions.

### Empty-state messages

Supply a custom empty-state message via `language.emptyTable`. This is preferred over PHP-side if/else logic that conditionally renders a paragraph or a table:

```js
NetMon.dt.init('#my-table', {
    language: { emptyTable: 'No records found.' },
});
```

All application tables should use DataTables unless there is a documented reason not to.

---

## Adding a New Theme

1. Create `public/assets/less/themes/{name}.less`.
2. Define a CSS selector that activates the theme, e.g. `[data-bs-theme="{name}"]`.
3. Override only the tokens that differ from the dark defaults.
4. Import the new file in `app.less` after `themes/light`.
5. Run `bash scripts/build-css.sh` and commit the updated `app.css`.

No component files need to be changed.

---

## App Shell HTML Classes

| Class | Element | Purpose |
|---|---|---|
| `.app-shell` | `<div>` | Outer flex container |
| `.app-sidebar` | `<aside>` | Fixed-position sidebar |
| `.app-sidebar-overlay` | `<div>` | Mobile tap-to-close overlay |
| `.app-main` | `<div>` | Right column (topbar + content + footer) |
| `.app-topbar` | `<header>` | Sticky topbar |
| `.app-content` | `<main>` | Scrollable page content |
| `.app-footer` | `<footer>` | Footer strip |

Sidebar modifier classes:
- `.collapsed` — hidden on desktop (toggled by sidebar button on lg+)
- `.open` — visible on mobile (toggled by sidebar button on < lg)

---

## See Also

- [architecture.md](architecture.md) — Application layer structure
- [dashboard.md](dashboard.md) — View rendering pattern and adding new pages
