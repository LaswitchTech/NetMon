# Dashboard

## Overview

`GET /` renders the main monitoring dashboard. It is the authenticated entry point for the browser-based UI after login.

The dashboard is fully server-rendered — no AJAX, no Chart.js, no polling. It loads once and shows the current state of the network at render time.

Authentication is enforced by the `WebAuth` middleware. Unauthenticated requests are redirected to `/auth/login`.

---

## Layout

```
┌─────────────────────────────────────────────────────────┐
│  Welcome back, <name>.  [All clear / Issues detected]   │
├──────────┬──────────┬──────────┬──────────┬─────────────┤
│  Total   │  Online  │ Offline  │  Open    │  Services   │
│ Devices  │          │          │ Alerts   │    Down     │
├─────────────────────────────────────────────────────────┤
│  Recent Alerts                          [View all →]    │
│  Device    Type           Service  Status  Last seen    │
│  …         device_offline  —       Open    …            │
│  …         service_down   :443     Resolved …           │
├─────────────────────────────────────────────────────────┤
│  Recent Device Checks                 [View devices →]  │
│  Device     Status   Latency   Checked at               │
│  …          Online    4 ms     …                        │
│  …          Offline   —        …                        │
└─────────────────────────────────────────────────────────┘
```

---

## Data Sources

| Section | Repository | Method | Limit |
|---------|-----------|--------|-------|
| Total Devices | `DeviceRepository` | `countAll()` | — |
| Devices Online | `DeviceRepository` | `countByStatus('online')` | — |
| Devices Offline | `DeviceRepository` | `countByStatus('offline')` | — |
| Open Alerts | `AlertRepository` | `countOpen()` | — |
| Services Down | `ServiceCheckRepository` | `countServicesDown()` | — |
| Recent Alerts | `AlertRepository` | `findRecent(10)` | 10 |
| Recent Checks | `DeviceCheckRepository` | `findRecentChecks(10)` | 10 |

All counts are single-row scalar queries (`COUNT(*)`). All feeds are bounded with `LIMIT 10`.

---

## Query Patterns

### Device counts

```sql
-- countAll()
SELECT COUNT(*) AS cnt FROM devices WHERE deleted_at IS NULL

-- countByStatus($status)
SELECT COUNT(*) AS cnt FROM devices WHERE deleted_at IS NULL AND status = ?
```

Both exclude soft-deleted devices.

### Alert open count

```sql
-- countOpen()
SELECT COUNT(*) AS cnt FROM alerts WHERE status = 'open'
```

Uses the `alerts.status` column directly (indexed in monitoring usage).

### Services down

```sql
-- countServicesDown()
SELECT COUNT(*) AS cnt
FROM   monitored_services ms
JOIN   devices d ON d.id = ms.device_id
WHERE  ms.monitoring_enabled = 1
  AND  ms.last_state = 'down'
  AND  d.deleted_at IS NULL
```

Uses the `monitored_services.last_state` summary column — no join to `service_checks` needed.

### Recent alerts

```sql
-- findRecent(10)
SELECT a.*, d.name AS device_name,
       ms.name AS service_name, ms.port AS service_port
FROM   alerts a
LEFT JOIN devices            d  ON d.id  = a.device_id
LEFT JOIN monitored_services ms ON ms.id = a.service_id
ORDER  BY a.last_seen_at DESC
LIMIT  10
```

### Recent device checks (all devices)

```sql
-- findRecentChecks(10)
SELECT dc.device_id, d.name AS device_name,
       dc.checked_at, dc.status, dc.latency_ms
FROM   device_checks dc
JOIN   devices d ON d.id = dc.device_id
ORDER  BY dc.checked_at DESC, dc.id DESC
LIMIT  10
```

Uses the indexed `device_checks.checked_at` column.

---

## What Each Section Represents

### Summary cards

| Card | Meaning |
|------|---------|
| **Total Devices** | All active (non-deleted) devices in inventory |
| **Online** | Devices whose most recent check returned `online` |
| **Offline** | Devices whose most recent check returned `offline` |
| **Open Alerts** | Unresolved alert conditions (device offline or service down) |
| **Services Down** | Monitored services whose last known state is `down` |

The **Offline** and **Open Alerts** cards change color to red when their count is non-zero. **Services Down** turns amber. This gives operators an at-a-glance health signal without reading the numbers.

The page heading also shows "All systems normal" (green) or "Issues detected" (red) derived from whether any of the three problem counters are non-zero.

### Recent Alerts

Shows the 10 most recently active alerts regardless of status. Links to each alert's detail page. Useful for confirming that conditions are being tracked and resolved correctly.

Status badges:
- Red `Open` — condition is active, unresolved
- Green `Resolved` — condition cleared automatically by the monitoring runner
- Amber `Ack'd` — acknowledged by an operator
- Grey `Suppressed` — silenced

### Recent Device Checks

Shows the 10 most recent raw check results across all devices. Unlike the alert feed, this is the raw monitoring stream — every pass appears here regardless of whether an alert was opened. Useful for verifying that the monitoring runner is active and reaching devices.

---

## Health Indicator Logic

```php
$hasProblems = $devicesOffline > 0 || $openAlerts > 0 || $servicesDown > 0;
```

When `$hasProblems` is false: "All systems normal" with green icon.
When `$hasProblems` is true: "Issues detected" with red icon.

This is a simple OR across the three problem signals. It does not account for suppressed alerts or disabled devices — those are intentionally excluded from the "problem" count.

---

## Controller

**`App\NetMon\Controllers\HomeController::index()`**

Loads all data before `ob_start()` so variables are in scope for the view:

```
$totalDevices   ← DeviceRepository::countAll()
$devicesOnline  ← DeviceRepository::countByStatus('online')
$devicesOffline ← DeviceRepository::countByStatus('offline')
$openAlerts     ← AlertRepository::countOpen()
$servicesDown   ← ServiceCheckRepository::countServicesDown()
$recentAlerts   ← AlertRepository::findRecent(10)
$recentChecks   ← DeviceCheckRepository::findRecentChecks(10)
```

Total: 7 queries per page load. All are simple indexed queries with a small result set.

---

## View Structure

```
app/Views/
    layouts/
        app.php          ← Reusable shell: topbar, sidebar, $content slot
    dashboard/
        index.php        ← Dashboard content fragment
```

### Shell layout (`app/Views/layouts/app.php`)

| Region | Description |
|--------|-------------|
| Topbar | Fixed, 56 px. App name (left), username + Sign Out (right). |
| Sidebar | 220 px, fixed. Navigation links. Hidden below 768 px. |
| Content | `<?= $content ?>` slot — receives the captured output of the dashboard view. |

**PHP variables expected by the layout:**

| Variable | Type | Description |
|----------|------|-------------|
| `$content` | string | HTML of the page fragment (from `ob_get_clean()`) |
| `$user` | array | Safe user record (`id`, `display_name`, `username`, `email`, …) |
| `$displayName` | string | `display_name` if non-empty, otherwise `username` |
| `$appName` | string | Application name from `config/app.php` |
| `$pageTitle` | string | Page title for `<title>` and sidebar active state |

---

## Performance Notes

- All count queries are `COUNT(*)` against indexed columns — O(1) at small scale, well-indexed at large scale.
- `findRecent(10)` and `findRecentChecks(10)` both use `ORDER BY … DESC LIMIT 10` against indexed timestamp columns — fast regardless of table size.
- No N+1 queries: alerts and checks both JOIN the device/service name in the same query.
- No aggregation or subquery loops in the view layer — all computation is done in SQL.

---

## Future Improvements

| Item | Notes |
|------|-------|
| Live refresh | Auto-reload the page every N seconds; or replace specific sections via AJAX |
| Summary charts | Uptime percentage, alert trends over time — requires aggregation queries |
| Device status donut | Quick visual breakdown of online/offline/unknown proportions |
| Alert age indicator | Highlight alerts open for more than N hours |
| Per-user filtering | Show only devices/alerts the authenticated user is responsible for |
| Top-N problem devices | Rank devices by most recent or most frequent failures |

---

## Rendering Pattern

```php
// Step 1 — capture the page-specific content
ob_start();
require $viewsPath . '/dashboard/index.php';
$content = ob_get_clean();

// Step 2 — render the full shell with the content injected
http_response_code(200);
header('Content-Type: text/html; charset=utf-8');
require $viewsPath . '/layouts/app.php';
```

Variables set before `ob_start()` are in scope for both the content view and the layout because PHP's `require` inherits the current variable scope.

---

## Authentication Guard

`GET /` uses the `WebAuth` middleware:

| Middleware | Auth failure response | Used for |
|------------|----------------------|----------|
| `WebAuth` | 302 redirect → `/auth/login` | HTML browser routes |
| `SessionAuth` | 401 JSON | AJAX / API routes |

See [auth.md](auth.md) for full middleware documentation.
