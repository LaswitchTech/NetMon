<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= htmlspecialchars($pageTitle) ?> &mdash; <?= htmlspecialchars($appName) ?></title>

    <!-- Bootstrap 5 -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <!-- Bootstrap Icons -->
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <!-- DataTables Bootstrap 5 -->
    <link rel="stylesheet" href="https://cdn.datatables.net/1.13.8/css/dataTables.bootstrap5.min.css">
    <link rel="stylesheet" href="https://cdn.datatables.net/responsive/2.5.0/css/responsive.bootstrap5.min.css">
    <!-- App theme -->
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>

<?php
// $displayName should be set by the controller before ob_start().
// Falls back to display_name or username from the user session row.
if (!isset($displayName) || $displayName === '') {
    $displayName = ($user['display_name'] ?? '') !== ''
        ? $user['display_name']
        : $user['username'];
}

// $activeSection may be set by controllers to mark a nav item as active
// independently of $pageTitle (e.g. sub-pages like Add/Edit Device).
$navActive = $activeSection ?? $pageTitle;
?>

<!-- Overlay for mobile sidebar -->
<div class="app-sidebar-overlay" id="sidebar-overlay"></div>

<div class="app-shell">

    <!-- ============================================================
         Sidebar
         ============================================================ -->
    <aside class="app-sidebar" id="app-sidebar">

        <a class="sidebar-brand" href="/">
            <span class="sidebar-brand-icon">
                <i class="bi bi-activity"></i>
            </span>
            <span class="sidebar-brand-text"><?= htmlspecialchars($appName) ?></span>
        </a>

        <nav class="sidebar-nav">

            <a class="sidebar-link <?= $navActive === 'Dashboard' ? 'active' : '' ?>" href="/">
                <i class="bi bi-speedometer2 sidebar-link-icon"></i>
                <span class="sidebar-link-label">Dashboard</span>
            </a>

            <div class="sidebar-section-label">Monitoring</div>

            <a class="sidebar-link disabled" href="#" tabindex="-1" aria-disabled="true"
               style="opacity:0.45;pointer-events:none;">
                <i class="bi bi-hdd-network sidebar-link-icon"></i>
                <span class="sidebar-link-label">Network</span>
            </a>
            <a class="sidebar-link <?= $navActive === 'Devices' ? 'active' : '' ?>" href="/devices">
                <i class="bi bi-cpu sidebar-link-icon"></i>
                <span class="sidebar-link-label">Devices</span>
            </a>
            <a class="sidebar-link <?= $navActive === 'Alerts' ? 'active' : '' ?>" href="/alerts">
                <i class="bi bi-bell sidebar-link-icon"></i>
                <span class="sidebar-link-label">Alerts</span>
            </a>
            <a class="sidebar-link <?= $navActive === 'Discovery' ? 'active' : '' ?>" href="/discovery">
                <i class="bi bi-radar sidebar-link-icon"></i>
                <span class="sidebar-link-label">Discovery</span>
            </a>

        </nav>

        <div class="sidebar-footer">
            <a class="sidebar-link" href="/tokens">
                <i class="bi bi-key sidebar-link-icon"></i>
                <span class="sidebar-link-label">API Tokens</span>
            </a>
        </div>

    </aside>

    <!-- ============================================================
         Main column
         ============================================================ -->
    <div class="app-main" id="app-main">

        <!-- Topbar -->
        <header class="app-topbar">
            <button class="topbar-toggle" id="sidebar-toggle" aria-label="Toggle sidebar">
                <i class="bi bi-list" style="font-size:1.25rem;"></i>
            </button>

            <span class="topbar-title"><?= htmlspecialchars($pageTitle) ?></span>

            <div class="topbar-actions">
                <div class="dropdown">
                    <a class="topbar-user" href="#" data-bs-toggle="dropdown" aria-expanded="false">
                        <span class="topbar-avatar">
                            <?= htmlspecialchars(mb_strtoupper(mb_substr($displayName, 0, 1))) ?>
                        </span>
                        <span class="topbar-username"><?= htmlspecialchars($displayName) ?></span>
                        <i class="bi bi-chevron-down" style="font-size:0.7rem;color:var(--app-text-muted);"></i>
                    </a>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><h6 class="dropdown-header"><?= htmlspecialchars($displayName) ?></h6></li>
                        <li><hr class="dropdown-divider"></li>
                        <li>
                            <button class="dropdown-item" id="js-logout">
                                <i class="bi bi-box-arrow-right me-2"></i>Sign Out
                            </button>
                        </li>
                    </ul>
                </div>
            </div>
        </header>

        <!-- Page content -->
        <main class="app-content">
            <?= $content ?>
        </main>

        <!-- Footer -->
        <footer class="app-footer">
            <span>&copy; <?= date('Y') ?> <?= htmlspecialchars($appName) ?></span>
        </footer>

    </div><!-- /.app-main -->

</div><!-- /.app-shell -->

<!-- Bootstrap 5 -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<!-- jQuery (required by DataTables) -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<!-- DataTables -->
<script src="https://cdn.datatables.net/1.13.8/js/jquery.dataTables.min.js"></script>
<script src="https://cdn.datatables.net/1.13.8/js/dataTables.bootstrap5.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/dataTables.responsive.min.js"></script>
<script src="https://cdn.datatables.net/responsive/2.5.0/js/responsive.bootstrap5.min.js"></script>

<script>
(function () {
    // Sign-out
    document.getElementById('js-logout').addEventListener('click', async function () {
        this.disabled = true;
        try {
            await fetch('/auth/logout', { method: 'POST' });
        } finally {
            window.location.href = '/auth/login';
        }
    });

    // Sidebar toggle
    var sidebar  = document.getElementById('app-sidebar');
    var main     = document.getElementById('app-main');
    var overlay  = document.getElementById('sidebar-overlay');
    var toggle   = document.getElementById('sidebar-toggle');
    var mq       = window.matchMedia('(max-width: 991px)');

    function isMobile() { return mq.matches; }

    toggle.addEventListener('click', function () {
        if (isMobile()) {
            sidebar.classList.toggle('open');
            overlay.classList.toggle('visible');
        } else {
            sidebar.classList.toggle('collapsed');
            main.classList.toggle('expanded');
        }
    });

    overlay.addEventListener('click', function () {
        sidebar.classList.remove('open');
        overlay.classList.remove('visible');
    });
})();
</script>

</body>
</html>
