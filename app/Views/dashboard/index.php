<?php
/**
 * Dashboard content fragment.
 *
 * Variables available (set by HomeController before ob_start):
 *   $user           (array)   — safe user record from the principal
 *   $permissions    (array)   — permission names for the authenticated user
 *   $appName        (string)  — application name from config
 *   $displayName    (string)  — display_name if set, otherwise username
 *   $totalDevices   (int)     — count of all active devices
 *   $devicesOnline  (int)     — count of devices with status = 'online'
 *   $devicesOffline (int)     — count of devices with status = 'offline'
 *   $openAlerts     (int)     — count of alerts with status = 'open'
 *   $servicesDown   (int)     — count of monitored services with last_state = 'down'
 *   $recentAlerts   (array)   — last 10 alerts (any status), from AlertRepository::findRecent
 *   $recentChecks   (array)   — last 10 device checks across all devices
 */

/**
 * Format an alert_type slug into a readable label.
 * e.g. 'device_offline' → 'Device offline'
 */
$formatType = static function (string $type): string {
    return ucfirst(str_replace('_', ' ', $type));
};

$hasProblems = $devicesOffline > 0 || $openAlerts > 0 || $servicesDown > 0;
?>

<!-- Page heading -->
<div class="d-flex align-items-start justify-content-between mb-4">
    <div>
        <h1 class="h4 fw-semibold mb-1">Dashboard</h1>
        <p class="text-muted mb-0 small">
            Welcome back, <strong><?= htmlspecialchars($displayName) ?></strong>.
            <?php if ($hasProblems): ?>
                <span class="text-danger ms-1">
                    <i class="bi bi-exclamation-circle-fill"></i> Issues detected.
                </span>
            <?php else: ?>
                <span class="text-success ms-1">
                    <i class="bi bi-check-circle-fill"></i> All systems normal.
                </span>
            <?php endif; ?>
        </p>
    </div>
    <span class="text-muted small mt-1" title="Page rendered at this time">
        <i class="bi bi-clock me-1"></i><?= date('H:i:s') ?>
    </span>
</div>

<!-- ================================================================
     Summary cards
     ================================================================ -->
<div class="row g-3 mb-4">

    <!-- Total Devices -->
    <div class="col-sm-6 col-xl">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded p-2 bg-primary bg-opacity-10 text-primary flex-shrink-0">
                    <i class="bi bi-cpu fs-4"></i>
                </div>
                <div class="min-w-0">
                    <div class="text-muted small">Total Devices</div>
                    <div class="fw-semibold fs-5"><?= $totalDevices ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Devices Online -->
    <div class="col-sm-6 col-xl">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded p-2 bg-success bg-opacity-10 text-success flex-shrink-0">
                    <i class="bi bi-check-circle fs-4"></i>
                </div>
                <div class="min-w-0">
                    <div class="text-muted small">Online</div>
                    <div class="fw-semibold fs-5 text-success"><?= $devicesOnline ?></div>
                </div>
            </div>
        </div>
    </div>

    <!-- Devices Offline -->
    <div class="col-sm-6 col-xl">
        <?php $offlineClass = $devicesOffline > 0 ? 'danger' : 'secondary'; ?>
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded p-2 bg-<?= $offlineClass ?> bg-opacity-10 text-<?= $offlineClass ?> flex-shrink-0">
                    <i class="bi bi-x-circle fs-4"></i>
                </div>
                <div class="min-w-0">
                    <div class="text-muted small">Offline</div>
                    <div class="fw-semibold fs-5 <?= $devicesOffline > 0 ? 'text-danger' : '' ?>">
                        <?= $devicesOffline ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Open Alerts -->
    <div class="col-sm-6 col-xl">
        <?php $alertClass = $openAlerts > 0 ? 'danger' : 'secondary'; ?>
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded p-2 bg-<?= $alertClass ?> bg-opacity-10 text-<?= $alertClass ?> flex-shrink-0">
                    <i class="bi bi-bell<?= $openAlerts > 0 ? '-fill' : '' ?> fs-4"></i>
                </div>
                <div class="min-w-0">
                    <div class="text-muted small">Open Alerts</div>
                    <div class="fw-semibold fs-5 <?= $openAlerts > 0 ? 'text-danger' : '' ?>">
                        <?= $openAlerts ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Services Down -->
    <div class="col-sm-6 col-xl">
        <?php $svcClass = $servicesDown > 0 ? 'warning' : 'secondary'; ?>
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded p-2 bg-<?= $svcClass ?> bg-opacity-10 text-<?= $svcClass ?> flex-shrink-0">
                    <i class="bi bi-hdd-network fs-4"></i>
                </div>
                <div class="min-w-0">
                    <div class="text-muted small">Services Down</div>
                    <div class="fw-semibold fs-5 <?= $servicesDown > 0 ? 'text-warning' : '' ?>">
                        <?= $servicesDown ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- ================================================================
     Recent Alerts
     ================================================================ -->
<div class="card border-0 shadow-sm mb-4">
    <div class="card-header bg-white border-bottom d-flex align-items-center justify-content-between py-2 px-3">
        <span class="fw-medium">Recent Alerts</span>
        <a href="/alerts" class="small text-decoration-none text-primary">
            View all <i class="bi bi-arrow-right ms-1"></i>
        </a>
    </div>

    <?php if (empty($recentAlerts)): ?>
    <div class="card-body text-center py-4 text-muted">
        <i class="bi bi-bell-slash opacity-25 d-block mb-2" style="font-size: 2rem"></i>
        No alerts recorded yet.
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 small">
            <thead class="table-light">
                <tr>
                    <th class="ps-3" style="width:22%">Device</th>
                    <th style="width:18%">Type</th>
                    <th style="width:14%">Service</th>
                    <th style="width:10%">Status</th>
                    <th style="width:18%" class="text-end pe-3">Last seen</th>
                </tr>
            </thead>
            <tbody>
<?php foreach ($recentAlerts as $alert): ?>
<?php
    $statusBadge = match ($alert['status']) {
        'open'         => ['class' => 'bg-danger',              'label' => 'Open'],
        'resolved'     => ['class' => 'bg-success',             'label' => 'Resolved'],
        'acknowledged' => ['class' => 'bg-warning text-dark',   'label' => "Ack'd"],
        'suppressed'   => ['class' => 'bg-secondary',           'label' => 'Suppressed'],
        default        => ['class' => 'bg-secondary',           'label' => htmlspecialchars($alert['status'])],
    };

    $deviceLabel = ($alert['device_name'] ?? '') !== ''
        ? htmlspecialchars($alert['device_name'])
        : 'Device #' . (int) $alert['device_id'];

    $serviceCell = (isset($alert['service_name']) && $alert['service_name'] !== null)
        ? htmlspecialchars($alert['service_name'])
          . '<span class="text-muted font-monospace ms-1">:' . (int) $alert['service_port'] . '</span>'
        : '<span class="text-muted">&mdash;</span>';
?>
                <tr>
                    <td class="ps-3 fw-medium">
                        <a href="/alerts/<?= (int) $alert['id'] ?>" class="text-decoration-none">
                            <?= $deviceLabel ?>
                        </a>
                    </td>
                    <td><?= htmlspecialchars($formatType($alert['alert_type'])) ?></td>
                    <td><?= $serviceCell ?></td>
                    <td>
                        <span class="badge <?= $statusBadge['class'] ?>">
                            <?= $statusBadge['label'] ?>
                        </span>
                    </td>
                    <td class="text-muted text-end pe-3"><?= htmlspecialchars($alert['last_seen_at']) ?></td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- ================================================================
     Recent Device Checks
     ================================================================ -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white border-bottom d-flex align-items-center justify-content-between py-2 px-3">
        <span class="fw-medium">Recent Device Checks</span>
        <a href="/devices" class="small text-decoration-none text-primary">
            View devices <i class="bi bi-arrow-right ms-1"></i>
        </a>
    </div>

    <?php if (empty($recentChecks)): ?>
    <div class="card-body text-center py-4 text-muted">
        <i class="bi bi-activity opacity-25 d-block mb-2" style="font-size: 2rem"></i>
        No monitoring data yet. Start the monitoring runner to collect check results.
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 small">
            <thead class="table-light">
                <tr>
                    <th class="ps-3" style="width:28%">Device</th>
                    <th style="width:14%">Status</th>
                    <th style="width:14%" class="text-end">Latency</th>
                    <th style="width:22%" class="text-end pe-3">Checked at</th>
                </tr>
            </thead>
            <tbody>
<?php foreach ($recentChecks as $check): ?>
<?php
    $checkBadge = match ($check['status']) {
        'online'  => ['class' => 'bg-success', 'label' => 'Online'],
        'offline' => ['class' => 'bg-danger',  'label' => 'Offline'],
        'timeout' => ['class' => 'bg-warning text-dark', 'label' => 'Timeout'],
        'error'   => ['class' => 'bg-secondary', 'label' => 'Error'],
        default   => ['class' => 'bg-secondary', 'label' => htmlspecialchars($check['status'])],
    };

    $latency = $check['latency_ms'] !== null
        ? (int) $check['latency_ms'] . ' ms'
        : '&mdash;';
?>
                <tr>
                    <td class="ps-3 fw-medium">
                        <a href="/devices/<?= (int) $check['device_id'] ?>" class="text-decoration-none">
                            <?= htmlspecialchars($check['device_name']) ?>
                        </a>
                    </td>
                    <td>
                        <span class="badge <?= $checkBadge['class'] ?>">
                            <?= $checkBadge['label'] ?>
                        </span>
                    </td>
                    <td class="text-end font-monospace"><?= $latency ?></td>
                    <td class="text-muted text-end pe-3"><?= htmlspecialchars($check['checked_at']) ?></td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
