<?php
/**
 * Device Detail content fragment.
 *
 * Layout: two-column page with a persistent left sidebar (overview, status,
 * duplicates) and a tabbed right workspace (Summary | Network | Notes).
 *
 * Variables available (set by DeviceController::show() before ob_start):
 *   $device             (array)  — row from DeviceRepository::findById(); keys:
 *                                  id, name, host, address, description, status,
 *                                  last_check_at, created_at
 *   $interfaces         (array)  — from DeviceRepository::findInterfacesWithAddresses();
 *                                  each entry: id, name, mac_address, is_management,
 *                                  description, addresses[]
 *   $openAlerts         (array)  — open alerts from AlertRepository::findByDevice()
 *                                  filtered to status='open'; each entry includes
 *                                  id, alert_type, service_name, service_port,
 *                                  occurrence_count, first_seen_at, last_seen_at
 *   $recentChecks       (array)  — from DeviceCheckRepository::findRecentByDevice();
 *                                  each entry: id, checked_at, status, latency_ms, message
 *   $historySeries      (array)  — from DeviceCheckRepository::findHistoryByDevice();
 *                                  each entry: checked_at, status, latency_ms (ASC, for graphs)
 *   $services           (array)  — from ServiceCheckRepository::findByDevice();
 *                                  each entry: id, name, protocol, port, monitoring_enabled,
 *                                  expected_state, last_state, last_check_at
 *   $serviceHistories   (array)  — keyed by service id; each value is an array of rows from
 *                                  ServiceCheckRepository::findHistoryByService() (ASC order)
 *   $possibleDuplicates (array)  — from DeviceRepository::possibleDuplicates()
 *   $notes              (array)  — rows from NoteRepository::findByEntity('device', $id)
 *   $user               (array)
 *   $permissions        (array)
 *   $appName            (string)
 *   $displayName        (string)
 */

// ── Detect a post-merge redirect ──────────────────────────────────────────
$mergedFrom = isset($_GET['merged']) && $_GET['merged'] !== ''
    ? trim($_GET['merged'])
    : null;

// ── Status badge mapping ──────────────────────────────────────────────────
$statusBadge = match ($device['status']) {
    'online'   => ['class' => 'bg-success',           'icon' => 'bi-check-circle-fill'],
    'offline'  => ['class' => 'bg-danger',            'icon' => 'bi-x-circle-fill'],
    'degraded' => ['class' => 'bg-warning text-dark', 'icon' => 'bi-exclamation-circle-fill'],
    default    => ['class' => 'bg-secondary',         'icon' => 'bi-question-circle-fill'],
};

// ── Helper ────────────────────────────────────────────────────────────────
function alertTypeLabel(string $type): string {
    return match ($type) {
        'device_offline' => 'Device Offline',
        'service_down'   => 'Service Down',
        'service_error'  => 'Service Error',
        default          => ucwords(str_replace('_', ' ', $type)),
    };
}

// ── Pre-compute service chart data ────────────────────────────────────────
// Done here (not inline) so $hasServiceHistory is available to the
// conditional Chart.js script tag and to the JS block below.
$serviceChartData = [];
foreach ($serviceHistories ?? [] as $svcId => $history) {
    if (empty($history)) {
        continue;
    }
    $isUp = array_map(fn($r) => $r['status'] === 'up', $history);
    $serviceChartData[$svcId] = [
        'labels'      => array_column($history, 'checked_at'),
        'latency'     => array_map(fn($r) => $r['latency_ms'], $history),
        // Status colours are semantic (green=up, red=down) and intentionally fixed.
        'pointColors' => array_map(fn($u) => $u ? 'rgba(25,135,84,0.9)' : 'rgba(220,53,69,0.9)', $isUp),
        'barColors'   => array_map(fn($u) => $u ? 'rgba(25,135,84,0.75)' : 'rgba(220,53,69,0.75)', $isUp),
    ];
}
$hasServiceHistory = !empty($serviceChartData);

// ── Pre-compute device-level chart data ───────────────────────────────────
$jsonLabels = $jsonLatency = $jsonPointColors = $jsonBarColors = 'null';
if (!empty($historySeries)) {
    $chartLabels   = array_column($historySeries, 'checked_at');
    $chartLatency  = array_map(fn($r) => $r['latency_ms'], $historySeries);
    $chartIsOnline = array_map(fn($r) => $r['status'] === 'online', $historySeries);
    // Status colours are semantic and intentionally fixed.
    $pointColors = array_map(
        fn($on) => $on ? 'rgba(25, 135, 84, 0.9)' : 'rgba(220, 53, 69, 0.9)',
        $chartIsOnline
    );
    $barColors = array_map(
        fn($on) => $on ? 'rgba(25, 135, 84, 0.75)' : 'rgba(220, 53, 69, 0.75)',
        $chartIsOnline
    );
    $jsonLabels      = json_encode($chartLabels,  JSON_UNESCAPED_UNICODE);
    $jsonLatency     = json_encode($chartLatency, JSON_UNESCAPED_UNICODE);
    $jsonPointColors = json_encode($pointColors,  JSON_UNESCAPED_UNICODE);
    $jsonBarColors   = json_encode($barColors,    JSON_UNESCAPED_UNICODE);
}

$checkCount     = count($recentChecks);
$openAlertCount = count($openAlerts);
?>

<?php if ($mergedFrom !== null): ?>
<!-- Merge success banner -->
<div class="alert alert-success alert-dismissible fade show d-flex align-items-center gap-2 mb-3" role="alert">
    <i class="bi bi-check-circle-fill flex-shrink-0"></i>
    <div>
        Device <strong><?= htmlspecialchars($mergedFrom) ?></strong> was merged into this device.
        All interfaces, services, and alerts have been transferred.
    </div>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<!-- Breadcrumb -->
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb small mb-0">
        <li class="breadcrumb-item">
            <a href="/devices" class="text-decoration-none">Devices</a>
        </li>
        <li class="breadcrumb-item active" aria-current="page">
            <?= htmlspecialchars($device['name']) ?>
        </li>
    </ol>
</nav>

<!-- Page heading + actions (always visible, full width) -->
<div class="d-flex align-items-start justify-content-between mb-4">
    <div>
        <h1 class="h4 fw-semibold mb-1"><?= htmlspecialchars($device['name']) ?></h1>
        <p class="small mb-0 text-muted">Device detail</p>
    </div>
    <div class="d-flex gap-2">
        <a href="/devices/<?= (int) $device['id'] ?>/edit"
           class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-pencil me-1"></i>Edit
        </a>
        <a href="/devices/<?= (int) $device['id'] ?>/merge"
           class="btn btn-sm btn-outline-warning">
            <i class="bi bi-arrow-left-right me-1"></i>Merge
        </a>
    </div>
</div>

<!-- ── Two-column layout ───────────────────────────────────────────────────
     Left:  device identity, live status, duplicate suggestions (always visible)
     Right: tabbed workspace — Summary | Network | Notes
     Columns collapse to stacked on screens narrower than md (768px).
 ─────────────────────────────────────────────────────────────────────────── -->
<div class="row g-4 align-items-start">

<!-- ════════════════════════════════════════════════════════════════════════
     LEFT COLUMN — context sidebar
     Always visible; provides stable reference while working in tabs.
     ════════════════════════════════════════════════════════════════════════ -->
<div class="col-md-4 col-lg-3">

    <!-- ── Overview ──────────────────────────────────────────────────────── -->
    <div class="card mb-3">
        <div class="card-body">
            <h6 class="section-label mb-3">Overview</h6>
            <dl class="row mb-0 small">
                <dt class="col-5 fw-normal text-muted">Address</dt>
                <dd class="col-7 font-monospace mb-2"><?= htmlspecialchars($device['address']) ?></dd>

<?php if (!empty($device['description'])): ?>
                <dt class="col-5 fw-normal text-muted">Description</dt>
                <dd class="col-7 mb-2"><?= htmlspecialchars($device['description']) ?></dd>
<?php endif; ?>

                <dt class="col-5 fw-normal text-muted">Added</dt>
                <dd class="col-7 mb-0"><?= htmlspecialchars($device['created_at']) ?></dd>
            </dl>
        </div>
    </div>

    <!-- ── Current Status ────────────────────────────────────────────────── -->
    <div class="card mb-3">
        <div class="card-body">
            <h6 class="section-label mb-3">Current Status</h6>
            <div class="d-flex align-items-center gap-2 mb-3">
                <span class="badge <?= $statusBadge['class'] ?> d-flex align-items-center gap-1 px-2 py-2">
                    <i class="bi <?= $statusBadge['icon'] ?>"></i>
                    <?= htmlspecialchars($device['status']) ?>
                </span>
            </div>
            <dl class="row mb-0 small">
                <dt class="col-5 fw-normal text-muted">Last check</dt>
                <dd class="col-7 font-monospace mb-0">
<?php if ($device['last_check_at'] !== null): ?>
                    <?= htmlspecialchars($device['last_check_at']) ?>
<?php else: ?>
                    <span class="text-muted">Never</span>
<?php endif; ?>
                </dd>
            </dl>
        </div>
    </div>

    <!-- ── Possible Duplicate Devices ────────────────────────────────────── -->
<?php if (!empty($possibleDuplicates)): ?>
    <div class="card card-accent-warning mb-3">
        <div class="card-body">
            <div class="d-flex align-items-start gap-2 mb-3">
                <i class="bi bi-exclamation-triangle text-warning mt-1 flex-shrink-0"></i>
                <div>
                    <h6 class="fw-semibold mb-0 small">Possible Duplicates</h6>
                    <p class="small mb-0 text-muted">
                        Suggestions only &mdash; no action taken automatically.
                    </p>
                </div>
            </div>
            <div class="d-flex flex-column gap-2">
<?php foreach ($possibleDuplicates as $dup): ?>
<?php
    $isMac = $dup['match_reason'] === 'mac';
    $signalBadge = $isMac
        ? '<span class="badge bg-warning text-dark">MAC</span>'
        : '<span class="badge bg-secondary">Hostname</span>';
?>
                <div class="p-2 rounded small" style="background:var(--app-panel-2);border:1px solid var(--app-border);">
                    <div class="fw-medium mb-1">
                        <a href="/devices/<?= (int) $dup['id'] ?>" class="text-decoration-none">
                            <?= htmlspecialchars($dup['name']) ?>
                        </a>
                        <?= $signalBadge ?>
                    </div>
                    <div class="text-muted font-monospace" style="font-size:.75rem;">
                        <?= htmlspecialchars($dup['match_value']) ?>
                    </div>
                    <div class="mt-2">
                        <a href="/devices/<?= (int) $device['id'] ?>/merge"
                           class="btn btn-sm btn-outline-warning py-0 px-2" style="font-size:.75rem;">
                            <i class="bi bi-arrow-left-right me-1"></i>Merge
                        </a>
                    </div>
                </div>
<?php endforeach; ?>
            </div>
            <p class="small mt-2 mb-0 text-muted" style="font-size:.72rem;">
                <i class="bi bi-info-circle me-1"></i>
                Merge form requires explicit confirmation.
            </p>
        </div>
    </div>
<?php endif; ?>

</div><!-- /left column -->

<!-- ════════════════════════════════════════════════════════════════════════
     RIGHT COLUMN — tabbed workspace
     ════════════════════════════════════════════════════════════════════════ -->
<div class="col-md-8 col-lg-9">

    <!-- Tab navigation -->
    <ul class="nav nav-tabs" id="device-tabs" role="tablist">
        <li class="nav-item" role="presentation">
            <button class="nav-link active" id="tab-summary-btn"
                    data-bs-toggle="tab" data-bs-target="#tab-summary"
                    type="button" role="tab"
                    aria-controls="tab-summary" aria-selected="true">
                Summary
<?php if ($openAlertCount > 0): ?>
                <span class="badge bg-danger ms-1"><?= $openAlertCount ?></span>
<?php endif; ?>
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-network-btn"
                    data-bs-toggle="tab" data-bs-target="#tab-network"
                    type="button" role="tab"
                    aria-controls="tab-network" aria-selected="false">
                Network
            </button>
        </li>
        <li class="nav-item" role="presentation">
            <button class="nav-link" id="tab-notes-btn"
                    data-bs-toggle="tab" data-bs-target="#tab-notes"
                    type="button" role="tab"
                    aria-controls="tab-notes" aria-selected="false">
                Notes
            </button>
        </li>
    </ul>

    <!-- Tab content -->
    <div class="tab-content mt-3" id="device-tabs-content">

        <!-- ══ Tab: Summary ══════════════════════════════════════════════════
             Default tab. Shows operational state: alerts, monitoring graphs,
             and recent check history.
             ════════════════════════════════════════════════════════════════ -->
        <div class="tab-pane fade show active" id="tab-summary"
             role="tabpanel" aria-labelledby="tab-summary-btn">

            <!-- Open Alerts ------------------------------------------------ -->
<?php if (!empty($openAlerts)): ?>
            <div class="card card-accent-danger mb-3">
                <div class="card-body">
                    <div class="d-flex align-items-start gap-2 mb-3">
                        <i class="bi bi-exclamation-triangle-fill text-danger mt-1 flex-shrink-0"></i>
                        <div>
                            <h6 class="fw-semibold mb-0">
                                Open Alerts
                                <span class="badge bg-danger ms-1"><?= $openAlertCount ?></span>
                            </h6>
                            <p class="small mb-0 text-muted">
                                Active alerts for this device. Click an alert to view details and take action.
                            </p>
                        </div>
                    </div>
                    <div class="table-responsive">
                        <table id="tbl-open-alerts" class="table table-sm align-middle mb-0 w-100">
                            <thead>
                                <tr>
                                    <th style="width:26%">Type</th>
                                    <th style="width:28%">Service</th>
                                    <th style="width:12%">Count</th>
                                    <th style="width:22%">Last Seen</th>
                                    <th class="text-end" style="width:12%"></th>
                                </tr>
                            </thead>
                            <tbody>
<?php foreach ($openAlerts as $alert): ?>
                                <tr>
                                    <td class="fw-medium"><?= htmlspecialchars(alertTypeLabel($alert['alert_type'])) ?></td>
                                    <td class="font-monospace small">
<?php if ($alert['service_name'] !== null): ?>
                                        <?= htmlspecialchars($alert['service_name']) ?>
                                        <span class="text-muted">:<?= (int) $alert['service_port'] ?></span>
<?php else: ?>
                                        <span class="text-muted">—</span>
<?php endif; ?>
                                    </td>
                                    <td class="small"><?= (int) $alert['occurrence_count'] ?></td>
                                    <td class="font-monospace small text-muted">
                                        <?= htmlspecialchars($alert['last_seen_at']) ?>
                                    </td>
                                    <td class="text-end">
                                        <a href="/alerts/<?= (int) $alert['id'] ?>"
                                           class="btn btn-sm btn-outline-danger py-0 px-2">
                                            View
                                        </a>
                                    </td>
                                </tr>
<?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
<?php else: ?>
            <div class="card mb-3">
                <div class="card-body d-flex align-items-center gap-2 py-2">
                    <i class="bi bi-check-circle text-success flex-shrink-0"></i>
                    <span class="small text-muted">No open alerts for this device.</span>
                </div>
            </div>
<?php endif; ?>

            <!-- Service History --------------------------------------------- -->
<?php if ($hasServiceHistory): ?>
            <div class="card mb-3">
                <div class="card-body">
                    <h6 class="section-label mb-3">Service History</h6>
<?php foreach ($services as $svc): ?>
<?php $svcId = (int) $svc['id']; ?>
<?php if (!isset($serviceChartData[$svcId])): continue; endif; ?>
                    <div class="mb-4">
                        <p class="small fw-medium mb-2">
                            <?= htmlspecialchars($svc['name']) ?>
                            <span class="font-monospace ms-1 text-muted">:<?= (int) $svc['port'] ?></span>
                            <span class="ms-2 text-muted">&mdash; last <?= count($serviceChartData[$svcId]['labels']) ?> checks</span>
                            <span class="ms-2">
                                <span class="badge bg-success" style="font-size:.62rem">Up</span>
                                <span class="badge bg-danger ms-1" style="font-size:.62rem">Down</span>
                            </span>
                        </p>
                        <div style="position:relative;height:110px" class="mb-1">
                            <canvas id="chart-svc-latency-<?= $svcId ?>"></canvas>
                        </div>
                        <div style="position:relative;height:28px">
                            <canvas id="chart-svc-status-<?= $svcId ?>"></canvas>
                        </div>
                    </div>
<?php endforeach; ?>
                </div>
            </div>
<?php endif; ?>

            <!-- Device Monitoring History ------------------------------------ -->
<?php if (!empty($historySeries)): ?>
            <div class="card mb-3">
                <div class="card-body">
                    <h6 class="section-label mb-3">Monitoring History</h6>
                    <div class="mb-3">
                        <p class="small mb-2 text-muted">
                            Latency (ms) &mdash; last <?= count($historySeries) ?> checks
                            <span class="ms-2">
                                <span class="badge bg-success" style="font-size:.65rem">Online</span>
                                <span class="badge bg-danger ms-1" style="font-size:.65rem">Offline</span>
                            </span>
                        </p>
                        <div style="position:relative;height:180px">
                            <canvas id="chart-latency"></canvas>
                        </div>
                    </div>
                    <div>
                        <p class="small mb-2 text-muted">Status timeline</p>
                        <div style="position:relative;height:40px">
                            <canvas id="chart-status"></canvas>
                        </div>
                    </div>
                </div>
            </div>
<?php endif; ?>

            <!-- Recent Check History ---------------------------------------- -->
            <div class="card">
                <div class="card-body">
                    <div class="d-flex align-items-center justify-content-between mb-3">
                        <h6 class="section-label mb-0">Recent Check History</h6>
                        <span class="small text-muted">
                            <?= $checkCount === 1 ? '1 result' : "{$checkCount} results" ?> (last 50)
                        </span>
                    </div>

<?php if (empty($recentChecks)): ?>
                    <p class="small mb-0 text-muted">
                        No check history yet. Run <code>php scripts/monitor.php</code> to record the first check.
                    </p>
<?php else: ?>
                    <div class="table-responsive">
                        <table id="tbl-recent-checks" class="table table-sm table-hover align-middle mb-0 w-100">
                            <thead>
                                <tr>
                                    <th style="width:30%">Checked At</th>
                                    <th style="width:18%">Status</th>
                                    <th style="width:18%">Latency</th>
                                    <th>Message</th>
                                </tr>
                            </thead>
                            <tbody>
<?php foreach ($recentChecks as $check): ?>
<?php
    $checkBadge = match ($check['status']) {
        'online'  => 'bg-success',
        'offline' => 'bg-danger',
        'timeout' => 'bg-warning text-dark',
        default   => 'bg-secondary',
    };
?>
                                <tr>
                                    <td class="font-monospace small"><?= htmlspecialchars($check['checked_at']) ?></td>
                                    <td>
                                        <span class="badge <?= $checkBadge ?>"><?= htmlspecialchars($check['status']) ?></span>
                                    </td>
                                    <td class="small">
                                        <?= $check['latency_ms'] !== null
                                            ? htmlspecialchars($check['latency_ms']) . ' ms'
                                            : '<span class="text-muted">—</span>' ?>
                                    </td>
                                    <td class="small text-muted">
                                        <?= !empty($check['message'])
                                            ? htmlspecialchars($check['message'])
                                            : '—' ?>
                                    </td>
                                </tr>
<?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
<?php endif; ?>
                </div>
            </div>

        </div><!-- /tab-summary -->

        <!-- ══ Tab: Network ══════════════════════════════════════════════════
             Configuration and structural data: interfaces, addresses,
             monitored services. Edit/add actions live here.
             ════════════════════════════════════════════════════════════════ -->
        <div class="tab-pane fade" id="tab-network"
             role="tabpanel" aria-labelledby="tab-network-btn">

            <!-- Error flash (interface/address/service delete failures) ------- -->
<?php if (!empty($_GET['error'])): ?>
            <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
                <?= htmlspecialchars($_GET['error']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
<?php endif; ?>

            <!-- Interfaces & Addresses --------------------------------------- -->
            <div class="card mb-3">
                <div class="card-body">
                    <h6 class="section-label mb-3">Interfaces &amp; Addresses</h6>
                    <div class="table-responsive">
                        <table id="tbl-interfaces" class="table table-sm align-middle mb-0 w-100">
                            <thead>
                                <tr>
                                    <th>Interface</th>
                                    <th>MAC Address</th>
                                    <th>Role</th>
                                    <th>Addresses</th>
                                    <th>Description</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
<?php foreach ($interfaces as $iface): ?>
                                <tr>
                                    <td class="fw-medium"><?= htmlspecialchars($iface['name']) ?></td>
                                    <td class="font-monospace small text-muted">
                                        <?= $iface['mac_address'] !== null
                                            ? htmlspecialchars($iface['mac_address'])
                                            : '—' ?>
                                    </td>
                                    <td>
<?php if ($iface['is_management']): ?>
                                        <span class="badge bg-primary-subtle text-primary border border-primary-subtle">management</span>
<?php else: ?>
                                        <span class="small text-muted">—</span>
<?php endif; ?>
                                    </td>
                                    <td class="font-monospace small">
<?php if (empty($iface['addresses'])): ?>
                                        <span class="text-muted">—</span>
                                        <a href="/devices/interfaces/<?= (int) $iface['id'] ?>/addresses/create"
                                           class="ms-1 small text-primary text-decoration-none">+ add</a>
<?php else: ?>
<?php foreach ($iface['addresses'] as $addr): ?>
                                        <div class="d-flex align-items-center gap-1">
                                            <span><?= htmlspecialchars($addr['address']) ?></span>
                                            <span class="text-muted">(<?= htmlspecialchars($addr['family']) ?>)</span>
<?php if ($addr['is_primary']): ?>
                                            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">primary</span>
<?php endif; ?>
                                            <a href="/devices/addresses/<?= (int) $addr['id'] ?>/edit"
                                               class="text-muted ms-1" title="Edit address">
                                                <i class="bi bi-pencil" style="font-size:.75rem;"></i>
                                            </a>
                                            <button type="button"
                                                    class="btn btn-link p-0 text-danger ms-1"
                                                    style="font-size:.75rem; line-height:1;"
                                                    title="Delete address"
                                                    data-bs-toggle="modal"
                                                    data-bs-target="#modal-del-addr-<?= (int) $addr['id'] ?>">
                                                <i class="bi bi-trash"></i>
                                            </button>
                                        </div>
<?php endforeach; ?>
                                        <a href="/devices/interfaces/<?= (int) $iface['id'] ?>/addresses/create"
                                           class="small text-primary text-decoration-none mt-1 d-inline-block">+ add address</a>
<?php endif; ?>
                                    </td>
                                    <td class="small text-muted">
                                        <?= !empty($iface['description'])
                                            ? htmlspecialchars($iface['description'])
                                            : '—' ?>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <a href="/devices/interfaces/<?= (int) $iface['id'] ?>/edit"
                                           class="btn btn-sm btn-outline-secondary py-0 px-1 me-1"
                                           title="Edit interface">
                                            <i class="bi bi-pencil"></i>
                                        </a>
<?php if (!$iface['is_management']): ?>
                                        <button type="button"
                                                class="btn btn-sm btn-outline-danger py-0 px-1"
                                                title="Delete interface"
                                                data-bs-toggle="modal"
                                                data-bs-target="#modal-del-iface-<?= (int) $iface['id'] ?>">
                                            <i class="bi bi-trash"></i>
                                        </button>
<?php endif; ?>
                                    </td>
                                </tr>
<?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Delete interface modals -->
<?php foreach ($interfaces as $iface): if ($iface['is_management']) continue; ?>
            <div class="modal fade" id="modal-del-iface-<?= (int) $iface['id'] ?>" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Delete Interface</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            Delete interface <strong><?= htmlspecialchars($iface['name']) ?></strong>?
                            This cannot be undone. All addresses on this interface must be removed first.
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <form method="post" action="/devices/interfaces/<?= (int) $iface['id'] ?>/delete" class="d-inline">
                                <button type="submit" class="btn btn-danger">Delete Interface</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
<?php endforeach; ?>

            <!-- Delete address modals -->
<?php foreach ($interfaces as $iface): foreach ($iface['addresses'] as $addr): ?>
            <div class="modal fade" id="modal-del-addr-<?= (int) $addr['id'] ?>" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Delete Address</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            Delete address <strong class="font-monospace"><?= htmlspecialchars($addr['address']) ?></strong>
                            from interface <strong><?= htmlspecialchars($iface['name']) ?></strong>?
<?php if ($addr['is_primary']): ?>
                            <div class="alert alert-warning mt-2 mb-0 small">
                                This is the primary address. Promote another address to primary before deleting this one.
                            </div>
<?php endif; ?>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <form method="post" action="/devices/addresses/<?= (int) $addr['id'] ?>/delete" class="d-inline">
                                <button type="submit" class="btn btn-danger">Delete Address</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
<?php endforeach; endforeach; ?>

            <!-- Monitored Services ------------------------------------------- -->
            <div class="card mb-3">
                <div class="card-body">
                    <h6 class="section-label mb-3">Monitored Services</h6>
                    <div class="table-responsive">
                        <table id="tbl-services" class="table table-sm align-middle mb-0 w-100">
                            <thead>
                                <tr>
                                    <th>Service</th>
                                    <th>Protocol / Port</th>
                                    <th>Monitoring</th>
                                    <th>Current State</th>
                                    <th>Last Checked</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
<?php foreach ($services as $svc): ?>
<?php
    $stateBadge = match ($svc['last_state']) {
        'up'    => 'bg-success',
        'down'  => 'bg-danger',
        default => 'bg-secondary',
    };
    $stateLabel = $svc['last_state'] ?? 'unknown';
?>
                                <tr>
                                    <td class="fw-medium"><?= htmlspecialchars($svc['name']) ?></td>
                                    <td class="font-monospace small">
                                        <?= htmlspecialchars(strtoupper($svc['protocol'])) ?>
                                        <span class="text-muted">/</span>
                                        <?= (int) $svc['port'] ?>
                                    </td>
                                    <td>
<?php if ($svc['monitoring_enabled']): ?>
                                        <span class="badge bg-success-subtle text-success border border-success-subtle">enabled</span>
<?php else: ?>
                                        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">disabled</span>
<?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="badge <?= $stateBadge ?>"><?= htmlspecialchars($stateLabel) ?></span>
                                    </td>
                                    <td class="small text-muted">
                                        <?= $svc['last_check_at'] !== null
                                            ? htmlspecialchars($svc['last_check_at'])
                                            : 'Never' ?>
                                    </td>
                                    <td class="text-end text-nowrap">
                                        <a href="/devices/services/<?= (int) $svc['id'] ?>/edit"
                                           class="btn btn-sm btn-outline-secondary py-0 px-1 me-1"
                                           title="Edit service">
                                            <i class="bi bi-pencil"></i>
                                        </a>
                                        <button type="button"
                                                class="btn btn-sm btn-outline-danger py-0 px-1"
                                                title="Delete service"
                                                data-bs-toggle="modal"
                                                data-bs-target="#modal-del-svc-<?= (int) $svc['id'] ?>">
                                            <i class="bi bi-trash"></i>
                                        </button>
                                    </td>
                                </tr>
<?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>

            <!-- Delete service modals -->
<?php foreach ($services as $svc): ?>
            <div class="modal fade" id="modal-del-svc-<?= (int) $svc['id'] ?>" tabindex="-1" aria-hidden="true">
                <div class="modal-dialog modal-dialog-centered">
                    <div class="modal-content">
                        <div class="modal-header">
                            <h5 class="modal-title">Delete Service</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <p class="mb-2">
                                Delete <strong><?= htmlspecialchars($svc['name']) ?></strong>
                                (<?= htmlspecialchars(strtoupper($svc['protocol'])) ?>/<?= (int) $svc['port'] ?>)?
                            </p>
                            <div class="alert alert-warning mb-0 small">
                                <i class="bi bi-exclamation-triangle me-1"></i>
                                This permanently removes the service and <strong>all its check history</strong>.
                                This cannot be undone.
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                            <form method="post" action="/devices/services/<?= (int) $svc['id'] ?>/delete" class="d-inline">
                                <button type="submit" class="btn btn-danger">Delete Service</button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
<?php endforeach; ?>

        </div><!-- /tab-network -->

        <!-- ══ Tab: Notes ════════════════════════════════════════════════════
             Notes attached to this device. Includes the add-note form.
             The controller redirects to /devices/{id}#notes after note
             operations; the JS below maps #notes → this tab pane.
             ════════════════════════════════════════════════════════════════ -->
        <div class="tab-pane fade" id="tab-notes"
             role="tabpanel" aria-labelledby="tab-notes-btn">

<?php
// Notes section — shared partial.
// $notes and $user are already in scope (set by DeviceController::show).
$noteBaseUrl = '/devices/' . (int) $device['id'];
require __DIR__ . '/../partials/notes-section.php';
?>

        </div><!-- /tab-notes -->

    </div><!-- /tab-content -->

</div><!-- /right column -->

</div><!-- /row -->

<?php if (!empty($historySeries) || $hasServiceHistory): ?>
<script src="/assets/vendor/chartjs/4.4.4/chart.umd.min.js"></script>
<?php endif; ?>

<script>
window.addEventListener('DOMContentLoaded', function () {

    // ── Theme tokens from CSS custom properties ───────────────────────────
    // Allows charts to adapt to dark/light theme without hardcoded colours.
    var cs           = getComputedStyle(document.documentElement);
    var colorMuted   = cs.getPropertyValue('--app-text-muted').trim();
    var colorBorder  = cs.getPropertyValue('--app-border').trim();
    var colorPrimary = cs.getPropertyValue('--app-primary').trim();

    // ── DataTables ────────────────────────────────────────────────────────
    //
    // Summary tab (default, visible at page load):
    //   tbl-open-alerts    — full layout, buttons:null (no create action)
    //   tbl-recent-checks  — full layout, buttons:null (read-only history)
    //
    // Network tab (hidden initially):
    //   tbl-interfaces     — full layout, Add Interface injected into buttons area
    //   tbl-services       — full layout, Add Service injected into buttons area
    //   → columns.adjust() is called on shown.bs.tab to fix widths after reveal.
    //
    // NOTE: Do NOT use initComplete to inject buttons (DataTables 1.13.x
    // calls initComplete with this = settings.oApi (internal functions), not
    // the public API — this.table() throws TypeError). Capture the dt return
    // value and use dt.buttons().container().prepend() instead.

    // Open Alerts — full layout, no Buttons container needed
<?php if (!empty($openAlerts)): ?>
    NetMon.dt.init('#tbl-open-alerts', {
        order      : [[3, 'desc']],
        columnDefs : [{ orderable: false, targets: 4 }],
        language   : { emptyTable: 'No open alerts.' },
        buttons    : null,
        dom        : "<'row g-2 align-items-center mb-2'<'col-sm-4 ms-auto'f>>" +
                     "rt" +
                     "<'row g-2 align-items-center mt-2'<'col-sm-4'l><'col-sm-4 text-center'i><'col-sm-4'p>>",
    });
<?php endif; ?>

    // Recent Check History — full layout, no Buttons container needed
<?php if (!empty($recentChecks)): ?>
    NetMon.dt.init('#tbl-recent-checks', {
        pageLength : 25,
        lengthMenu : [10, 25, 50],
        order      : [[0, 'desc']],
        language   : { emptyTable: 'No check history recorded yet.' },
        buttons    : null,
        dom        : "<'row g-2 align-items-center mb-2'<'col-sm-4 ms-auto'f>>" +
                     "rt" +
                     "<'row g-2 align-items-center mt-2'<'col-sm-4'l><'col-sm-4 text-center'i><'col-sm-4'p>>",
    });
<?php endif; ?>

    // Interfaces & Addresses — full layout + Add Interface action
    // Columns: Interface(0), MAC(1), Role(2), Addresses(3), Description(4), Actions(5)
    // Addresses(3) and Actions(5) are not orderable (HTML content / buttons).
    var dtIfaces = NetMon.dt.init('#tbl-interfaces', {
        order      : [[0, 'asc']],
        columnDefs : [{ orderable: false, targets: [3, 5] }],
        language   : { emptyTable: 'No interface records found.' },
    });
    dtIfaces.buttons().container().prepend(
        '<a href="/devices/<?= (int) $device['id'] ?>/interfaces/create" class="btn btn-sm btn-outline-primary me-1">' +
        '<i class="bi bi-plus-lg me-1"></i>Add Interface</a>'
    );

    // Monitored Services — full layout + Add Service action
    // Columns: Service(0), Protocol/Port(1), Monitoring(2), State(3), Last Checked(4), Actions(5)
    // Actions(5) is not orderable.
    var dtSvcs = NetMon.dt.init('#tbl-services', {
        order      : [[0, 'asc']],
        columnDefs : [{ orderable: false, targets: 5 }],
        language   : { emptyTable: 'No services configured for this device.' },
    });
    dtSvcs.buttons().container().prepend(
        '<a href="/devices/<?= (int) $device['id'] ?>/services/create" class="btn btn-sm btn-outline-primary me-1">' +
        '<i class="bi bi-plus-lg me-1"></i>Add Service</a>'
    );

    // ── Network tab: fix DataTables column widths after reveal ────────────
    // When tbl-interfaces and tbl-services initialize inside a hidden tab pane
    // (display:none), DataTables cannot calculate column widths. Calling
    // columns.adjust() after the tab becomes visible corrects this.
    var networkTabBtn = document.getElementById('tab-network-btn');
    if (networkTabBtn) {
        networkTabBtn.addEventListener('shown.bs.tab', function () {
            dtIfaces.columns.adjust();
            dtSvcs.columns.adjust();
        });
    }

    // ── Tab state: hash-based activation ─────────────────────────────────
    // Supports two use cases:
    //   1. Controller redirects — DeviceController adds #notes to note action
    //      redirects; the map below translates #notes → #tab-notes.
    //   2. Tab persistence — clicking a tab updates the URL hash so the
    //      browser Back button and page reload restore the active tab.
    var hashMap = {
        '#notes':       '#tab-notes',    // controller redirect alias
        '#tab-summary': '#tab-summary',
        '#tab-network': '#tab-network',
        '#tab-notes':   '#tab-notes',
    };

    // Activate tab from URL hash on page load
    var initialHash = window.location.hash;
    if (initialHash && hashMap[initialHash]) {
        var targetBtn = document.querySelector(
            '#device-tabs [data-bs-target="' + hashMap[initialHash] + '"]'
        );
        if (targetBtn) {
            new bootstrap.Tab(targetBtn).show();
        }
    }

    // Update URL hash when tab changes (persist tab across navigation)
    // Uses #notes for the Notes tab to stay consistent with controller redirects.
    var tabHashOut = {
        '#tab-summary': '#tab-summary',
        '#tab-network': '#tab-network',
        '#tab-notes':   '#notes',
    };
    document.querySelectorAll('#device-tabs [data-bs-toggle="tab"]').forEach(function (btn) {
        btn.addEventListener('shown.bs.tab', function (e) {
            var outHash = tabHashOut[e.target.dataset.bsTarget] || e.target.dataset.bsTarget;
            history.replaceState(null, '', outHash);
        });
    });

    // ── Auto-activate Network tab on interface/address/service errors ─────
    // When a delete fails, the controller redirects with ?error=... (no hash).
    // Auto-switch to the Network tab so the error flash is immediately visible.
<?php if (!empty($_GET['error'])): ?>
    var netBtn = document.getElementById('tab-network-btn');
    if (netBtn) {
        new bootstrap.Tab(netBtn).show();
    }
<?php endif; ?>

<?php if (!empty($historySeries) || $hasServiceHistory): ?>
    // ── Chart helpers ─────────────────────────────────────────────────────
    // Point/bar colours for status charts are semantic (green=up/online,
    // red=down/offline) and intentionally fixed regardless of theme.
    // Grid, tick, and line colours adapt to the active theme via CSS vars.

    function makeLatencyChart(canvasId, labels, latency, pointColors, offlineLabel) {
        new Chart(document.getElementById(canvasId), {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    data: latency,
                    borderColor: colorPrimary,
                    borderWidth: 1.5,
                    pointRadius: 3,
                    pointHoverRadius: 5,
                    pointBackgroundColor: pointColors,
                    pointBorderColor: pointColors,
                    fill: false,
                    tension: 0.2,
                    spanGaps: false
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                return ctx.raw !== null ? ctx.raw + ' ms' : offlineLabel;
                            }
                        }
                    }
                },
                scales: {
                    x: {
                        ticks: { maxTicksLimit: 8, maxRotation: 45, font: { size: 10 }, color: colorMuted },
                        grid:  { color: colorBorder }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { font: { size: 10 }, color: colorMuted, callback: function (v) { return v + ' ms'; } },
                        grid: { color: colorBorder }
                    }
                }
            }
        });
    }

    function makeStatusChart(canvasId, labels, barColors, upLabel, downLabel) {
        var ones = labels.map(function () { return 1; });
        new Chart(document.getElementById(canvasId), {
            type: 'bar',
            data: {
                labels: labels,
                datasets: [{
                    data: ones,
                    backgroundColor: barColors,
                    borderWidth: 0,
                    barPercentage: 1.0,
                    categoryPercentage: 1.0
                }]
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: { display: false },
                    tooltip: {
                        callbacks: {
                            label: function (ctx) {
                                return barColors[ctx.dataIndex].includes('135, 84') ? upLabel : downLabel;
                            }
                        }
                    }
                },
                scales: {
                    x: { display: false },
                    y: { display: false, min: 0, max: 1 }
                }
            }
        });
    }

    // ── Device-level charts ───────────────────────────────────────────────
<?php if (!empty($historySeries)): ?>
    makeLatencyChart(
        'chart-latency',
        <?= $jsonLabels ?>,
        <?= $jsonLatency ?>,
        <?= $jsonPointColors ?>,
        'Offline / no data'
    );
    makeStatusChart(
        'chart-status',
        <?= $jsonLabels ?>,
        <?= $jsonBarColors ?>,
        'Online',
        'Offline'
    );
<?php endif; ?>

    // ── Service-level charts ──────────────────────────────────────────────
<?php foreach ($serviceChartData as $svcId => $d): ?>
    makeLatencyChart(
        'chart-svc-latency-<?= $svcId ?>',
        <?= json_encode($d['labels'],      JSON_UNESCAPED_UNICODE) ?>,
        <?= json_encode($d['latency'],     JSON_UNESCAPED_UNICODE) ?>,
        <?= json_encode($d['pointColors'], JSON_UNESCAPED_UNICODE) ?>,
        'Down / no data'
    );
    makeStatusChart(
        'chart-svc-status-<?= $svcId ?>',
        <?= json_encode($d['labels'],    JSON_UNESCAPED_UNICODE) ?>,
        <?= json_encode($d['barColors'], JSON_UNESCAPED_UNICODE) ?>,
        'Up',
        'Down'
    );
<?php endforeach; ?>
<?php endif; ?>

});
</script>
