<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle) ?> &mdash; <?= htmlspecialchars($appName) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { padding-top: 56px; background: #f0f2f5; }

        /* Sidebar */
        .app-sidebar {
            width: 220px;
            min-height: calc(100vh - 56px);
            flex-shrink: 0;
            background: #fff;
            border-right: 1px solid #dee2e6;
        }
        .app-sidebar .nav-section-label {
            font-size: .68rem;
            font-weight: 700;
            letter-spacing: .07em;
            text-transform: uppercase;
            color: #adb5bd;
            padding: 16px 16px 4px;
        }
        .app-sidebar .nav-link {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #495057;
            border-radius: 6px;
            margin: 1px 8px;
            padding: 8px 10px;
            font-size: .9rem;
        }
        .app-sidebar .nav-link:hover  { background: #f0f2f5; color: #212529; }
        .app-sidebar .nav-link.active { background: #0d6efd; color: #fff; }
        .app-sidebar .nav-link.disabled { color: #ced4da; pointer-events: none; }

        /* Main content */
        .app-main { min-width: 0; min-height: calc(100vh - 56px); }

        /* Hide sidebar on small screens */
        @media (max-width: 767.98px) {
            .app-sidebar { display: none; }
        }
    </style>
</head>
<body>

<?php
// $displayName should be set by the controller before ob_start().
// This fallback covers any future controller that omits it.
if (!isset($displayName) || $displayName === '') {
    $displayName = ($user['display_name'] ?? '') !== ''
        ? $user['display_name']
        : $user['username'];
}
?>
<!-- ============================================================
     Topbar
     ============================================================ -->
<nav class="navbar navbar-expand-md navbar-light bg-white border-bottom fixed-top shadow-sm" style="height:56px">
    <div class="container-fluid px-3">

        <a class="navbar-brand fw-semibold me-4" href="/">
            <?= htmlspecialchars($appName) ?>
        </a>

        <div class="ms-auto d-flex align-items-center gap-3">

            <!-- User identity -->
            <div class="d-flex align-items-center gap-2">
                <i class="bi bi-person-circle text-secondary"></i>
                <span class="small fw-medium d-none d-sm-inline">
                    <?= htmlspecialchars($displayName) ?>
                </span>
            </div>

            <!-- Sign Out — always visible -->
            <button class="btn btn-sm btn-outline-secondary" id="js-logout">
                <i class="bi bi-box-arrow-right"></i>
                <span class="d-none d-sm-inline ms-1">Sign Out</span>
            </button>

        </div>

    </div>
</nav>

<!-- ============================================================
     Page body — sidebar + main
     ============================================================ -->
<div class="d-flex">

    <!-- Sidebar -->
    <aside class="app-sidebar pt-2">
        <nav class="nav flex-column">

            <?php
            // $activeSection may be set by controllers to mark a nav item as active
            // independently of $pageTitle (e.g. sub-pages like Add/Edit Device).
            // Falls back to $pageTitle for backward compatibility.
            $navActive = $activeSection ?? $pageTitle;
            ?>
            <a class="nav-link <?= $navActive === 'Dashboard' ? 'active' : '' ?>" href="/">
                <i class="bi bi-speedometer2"></i>
                Dashboard
            </a>

            <div class="nav-section-label mt-2">Monitoring</div>

            <a class="nav-link disabled" href="#" tabindex="-1" aria-disabled="true">
                <i class="bi bi-hdd-network"></i>
                Network
            </a>
            <a class="nav-link <?= $navActive === 'Devices' ? 'active' : '' ?>" href="/devices">
                <i class="bi bi-cpu"></i>
                Devices
            </a>
            <a class="nav-link <?= $navActive === 'Alerts' ? 'active' : '' ?>" href="/alerts">
                <i class="bi bi-bell"></i>
                Alerts
            </a>
            <a class="nav-link <?= $navActive === 'Discovery' ? 'active' : '' ?>" href="/discovery">
                <i class="bi bi-radar"></i>
                Discovery
            </a>

        </nav>
    </aside>

    <!-- Main content area — page-specific content goes here -->
    <main class="app-main flex-fill p-4">
        <?= $content ?>
    </main>

</div><!-- /.d-flex -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
(function () {
    document.getElementById('js-logout').addEventListener('click', async function () {
        this.disabled = true;
        try {
            await fetch('/auth/logout', { method: 'POST' });
        } finally {
            window.location.href = '/auth/login';
        }
    });
})();
</script>

</body>
</html>
