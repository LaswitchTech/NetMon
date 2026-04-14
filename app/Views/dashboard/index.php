<?php
/**
 * Dashboard content fragment.
 *
 * Variables available (set by HomeController before ob_start):
 *   $user        (array)   — safe user record from the principal
 *   $permissions (array)   — permission names for the authenticated user
 *   $appName     (string)  — application name from config
 */
?>

<!-- Page heading -->
<div class="mb-4">
    <h1 class="h4 fw-semibold mb-1">Dashboard</h1>
    <p class="text-muted mb-0 small">
        Welcome back, <strong><?= htmlspecialchars($displayName) ?></strong>.
    </p>
</div>

<!-- Summary cards -->
<div class="row g-3 mb-4">

    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded p-2 bg-success bg-opacity-10 text-success">
                    <i class="bi bi-check-circle fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small">Status</div>
                    <div class="fw-semibold">Operational</div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded p-2 bg-primary bg-opacity-10 text-primary">
                    <i class="bi bi-hdd-network fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small">Devices</div>
                    <div class="fw-semibold text-muted">&mdash;</div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded p-2 bg-warning bg-opacity-10 text-warning">
                    <i class="bi bi-bell fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small">Alerts</div>
                    <div class="fw-semibold text-muted">&mdash;</div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded p-2 bg-info bg-opacity-10 text-info">
                    <i class="bi bi-activity fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small">Uptime</div>
                    <div class="fw-semibold text-muted">&mdash;</div>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- Placeholder content area -->
<div class="card border-0 shadow-sm">
    <div class="card-body text-center py-5">
        <i class="bi bi-bar-chart-line text-muted opacity-25" style="font-size: 3rem"></i>
        <p class="text-muted mt-3 mb-1">No monitoring data yet.</p>
        <p class="text-muted small mb-0">
            Network devices and alert feeds will appear here once monitoring modules are configured.
        </p>
    </div>
</div>
