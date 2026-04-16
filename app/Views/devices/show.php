<?php
/**
 * Device Detail content fragment — read-only.
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
 *                                  each entry: checked_at, status, latency_ms (ASC order, for graphs)
 *   $services           (array)  — from ServiceCheckRepository::findByDevice();
 *                                  each entry: id, name, protocol, port, monitoring_enabled,
 *                                  expected_state, last_state, last_check_at
 *   $serviceHistories   (array)  — keyed by service id; each value is an array of rows from
 *                                  ServiceCheckRepository::findHistoryByService() (ASC order);
 *                                  each row: checked_at, status, latency_ms
 *   $possibleDuplicates (array)  — from DeviceRepository::possibleDuplicates();
 *                                  each entry: id, name, address, match_reason, match_value
 *                                  empty array if no suggestions
 *   $user               (array)
 *   $permissions        (array)
 *   $appName            (string)
 *   $displayName        (string)
 */

// Detect a post-merge redirect — ?merged=SourceName
$mergedFrom = isset($_GET['merged']) && $_GET['merged'] !== ''
    ? trim($_GET['merged'])
    : null;

$statusBadge = match ($device['status']) {
    'online'   => ['class' => 'bg-success',              'icon' => 'bi-check-circle-fill'],
    'offline'  => ['class' => 'bg-danger',               'icon' => 'bi-x-circle-fill'],
    'degraded' => ['class' => 'bg-warning text-dark',    'icon' => 'bi-exclamation-circle-fill'],
    default    => ['class' => 'bg-secondary',            'icon' => 'bi-question-circle-fill'],
};

$checkCount = count($recentChecks);

// Human-readable alert type labels
function alertTypeLabel(string $type): string {
    return match ($type) {
        'device_offline' => 'Device Offline',
        'service_down'   => 'Service Down',
        'service_error'  => 'Service Error',
        default          => ucwords(str_replace('_', ' ', $type)),
    };
}
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

<!-- Page heading + actions -->
<div class="d-flex align-items-start justify-content-between mb-4">
    <div>
        <h1 class="h4 fw-semibold mb-1"><?= htmlspecialchars($device['name']) ?></h1>
        <p class="small mb-0" style="color:var(--app-text-muted)">
            Device detail &mdash; read only
        </p>
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

<!-- ── Overview + Status ──────────────────────────────────────────────────── -->
<div class="row g-3 mb-3">

    <!-- Overview card -->
    <div class="col-md-8">
        <div class="card h-100">
            <div class="card-body">
                <h6 class="section-label mb-3">Overview</h6>
                <dl class="row mb-0 small">
                    <dt class="col-sm-4 fw-normal" style="color:var(--app-text-muted)">Name</dt>
                    <dd class="col-sm-8 fw-medium mb-2"><?= htmlspecialchars($device['name']) ?></dd>

                    <dt class="col-sm-4 fw-normal" style="color:var(--app-text-muted)">Management address</dt>
                    <dd class="col-sm-8 font-monospace mb-2"><?= htmlspecialchars($device['address']) ?></dd>

<?php if (!empty($device['description'])): ?>
                    <dt class="col-sm-4 fw-normal" style="color:var(--app-text-muted)">Description</dt>
                    <dd class="col-sm-8 mb-2"><?= htmlspecialchars($device['description']) ?></dd>
<?php endif; ?>

                    <dt class="col-sm-4 fw-normal" style="color:var(--app-text-muted)">Added</dt>
                    <dd class="col-sm-8 mb-0"><?= htmlspecialchars($device['created_at']) ?></dd>
                </dl>
            </div>
        </div>
    </div>

    <!-- Status card -->
    <div class="col-md-4">
        <div class="card h-100">
            <div class="card-body d-flex flex-column">
                <h6 class="section-label mb-3">Current Status</h6>
                <div class="d-flex align-items-center gap-2 mb-3">
                    <span class="badge <?= $statusBadge['class'] ?> d-flex align-items-center gap-1 px-2 py-2">
                        <i class="bi <?= $statusBadge['icon'] ?>"></i>
                        <?= htmlspecialchars($device['status']) ?>
                    </span>
                </div>
                <dl class="row mb-0 small mt-auto">
                    <dt class="col-12 fw-normal mb-1" style="color:var(--app-text-muted)">Last check</dt>
                    <dd class="col-12 font-monospace mb-0">
<?php if ($device['last_check_at'] !== null): ?>
                        <?= htmlspecialchars($device['last_check_at']) ?>
<?php else: ?>
                        <span style="color:var(--app-text-muted)">Never checked</span>
<?php endif; ?>
                    </dd>
                </dl>
            </div>
        </div>
    </div>

</div>

<!-- ── Open Alerts ────────────────────────────────────────────────────────── -->
<?php if (!empty($openAlerts)): ?>
<div class="card card-accent-danger mb-3">
    <div class="card-body">
        <div class="d-flex align-items-start gap-2 mb-3">
            <i class="bi bi-exclamation-triangle-fill text-danger mt-1 flex-shrink-0"></i>
            <div>
                <h6 class="fw-semibold mb-0">
                    Open Alerts
                    <span class="badge bg-danger ms-1"><?= count($openAlerts) ?></span>
                </h6>
                <p class="small mb-0" style="color:var(--app-text-muted)">
                    Active alerts for this device. Click an alert to view details and take action.
                </p>
            </div>
        </div>

        <div class="table-responsive">
            <table id="tbl-open-alerts" class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width:28%">Type</th>
                        <th style="width:28%">Service</th>
                        <th style="width:14%">Occurrences</th>
                        <th style="width:22%">Last Seen</th>
                        <th class="text-end" style="width:8%"></th>
                    </tr>
                </thead>
                <tbody>
<?php foreach ($openAlerts as $alert): ?>
                    <tr>
                        <td class="fw-medium"><?= htmlspecialchars(alertTypeLabel($alert['alert_type'])) ?></td>
                        <td class="font-monospace small">
<?php if ($alert['service_name'] !== null): ?>
                            <?= htmlspecialchars($alert['service_name']) ?>
                            <span style="color:var(--app-text-muted)">:<?= (int) $alert['service_port'] ?></span>
<?php else: ?>
                            <span style="color:var(--app-text-muted)">—</span>
<?php endif; ?>
                        </td>
                        <td class="small"><?= (int) $alert['occurrence_count'] ?></td>
                        <td class="font-monospace small" style="color:var(--app-text-muted)">
                            <?= htmlspecialchars($alert['last_seen_at']) ?>
                        </td>
                        <td class="text-end">
                            <a href="/alerts/<?= (int) $alert['id'] ?>"
                               class="btn btn-sm btn-outline-danger">
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
        <span class="small" style="color:var(--app-text-muted)">No open alerts for this device.</span>
    </div>
</div>
<?php endif; ?>

<!-- ── Possible Duplicate Devices ────────────────────────────────────────── -->
<?php if (!empty($possibleDuplicates)): ?>
<div class="card card-accent-warning mb-3">
    <div class="card-body">
        <div class="d-flex align-items-start gap-2 mb-3">
            <i class="bi bi-exclamation-triangle text-warning mt-1 flex-shrink-0"></i>
            <div>
                <h6 class="fw-semibold mb-0">Possible Duplicate Devices</h6>
                <p class="small mb-0" style="color:var(--app-text-muted)">
                    The following devices share a strong identity signal with this one.
                    These are <strong>suggestions only</strong> — no action is taken automatically.
                    Review each carefully before deciding to merge.
                </p>
            </div>
        </div>

        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width:30%">Device</th>
                        <th style="width:22%">Address</th>
                        <th style="width:22%">Signal</th>
                        <th style="width:26%">Matched Value</th>
                        <th class="text-end">Action</th>
                    </tr>
                </thead>
                <tbody>
<?php foreach ($possibleDuplicates as $dup): ?>
<?php
    $isMac = $dup['match_reason'] === 'mac';
    $signalBadge = $isMac
        ? '<span class="badge bg-warning text-dark">MAC match</span>'
        : '<span class="badge bg-secondary">Hostname match</span>';
    $signalNote = $isMac
        ? ''
        : '<span class="d-block small fst-italic" style="color:var(--app-text-muted)">weaker signal</span>';
?>
                    <tr>
                        <td class="fw-medium">
                            <a href="/devices/<?= (int) $dup['id'] ?>" class="text-decoration-none">
                                <?= htmlspecialchars($dup['name']) ?>
                            </a>
                        </td>
                        <td class="font-monospace small" style="color:var(--app-text-muted)">
                            <?= htmlspecialchars($dup['address'] ?? '—') ?>
                        </td>
                        <td>
                            <?= $signalBadge ?>
                            <?= $signalNote ?>
                        </td>
                        <td class="font-monospace small">
                            <?= htmlspecialchars($dup['match_value']) ?>
                        </td>
                        <td class="text-end">
                            <a href="/devices/<?= (int) $device['id'] ?>/merge"
                               class="btn btn-sm btn-outline-warning">
                                <i class="bi bi-arrow-left-right me-1"></i>Merge
                            </a>
                        </td>
                    </tr>
<?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="small mt-2 mb-0" style="color:var(--app-text-muted)">
            <i class="bi bi-info-circle me-1"></i>
            The Merge button opens the merge form — you choose the direction and confirm.
            Nothing is merged automatically.
        </p>
    </div>
</div>
<?php endif; ?>

<!-- ── Interfaces & Addresses ─────────────────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-body">
        <h6 class="section-label mb-3">Interfaces &amp; Addresses</h6>

<?php if (empty($interfaces)): ?>
        <p class="small mb-0" style="color:var(--app-text-muted)">No interface records found.</p>
<?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width:20%">Interface</th>
                        <th style="width:18%">MAC Address</th>
                        <th style="width:12%">Role</th>
                        <th style="width:25%">Addresses</th>
                        <th>Description</th>
                    </tr>
                </thead>
                <tbody>
<?php foreach ($interfaces as $iface): ?>
                    <tr>
                        <td class="fw-medium"><?= htmlspecialchars($iface['name']) ?></td>
                        <td class="font-monospace small" style="color:var(--app-text-muted)">
                            <?= $iface['mac_address'] !== null
                                ? htmlspecialchars($iface['mac_address'])
                                : '<span style="color:var(--app-text-muted)">—</span>' ?>
                        </td>
                        <td>
<?php if ($iface['is_management']): ?>
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle">management</span>
<?php else: ?>
                            <span style="color:var(--app-text-muted)" class="small">—</span>
<?php endif; ?>
                        </td>
                        <td class="font-monospace small">
<?php if (empty($iface['addresses'])): ?>
                            <span style="color:var(--app-text-muted)">—</span>
<?php else: ?>
<?php foreach ($iface['addresses'] as $addr): ?>
                            <div>
                                <?= htmlspecialchars($addr['address']) ?>
                                <span style="color:var(--app-text-muted)">(<?= htmlspecialchars($addr['family']) ?>)</span>
<?php if ($addr['is_primary']): ?>
                                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle ms-1">primary</span>
<?php endif; ?>
                            </div>
<?php endforeach; ?>
<?php endif; ?>
                        </td>
                        <td class="small" style="color:var(--app-text-muted)">
                            <?= !empty($iface['description'])
                                ? htmlspecialchars($iface['description'])
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

<!-- ── Monitored Services ─────────────────────────────────────────────────── -->
<div class="card mb-3">
    <div class="card-body">
        <h6 class="section-label mb-3">Monitored Services</h6>

<?php if (empty($services)): ?>
        <p class="small mb-0" style="color:var(--app-text-muted)">
            No services configured for this device.
            Add services via <code>php scripts/seed.php MonitoredServiceSeed</code> (dev)
            or the service management UI once available.
        </p>
<?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th style="width:28%">Service</th>
                        <th style="width:18%">Protocol / Port</th>
                        <th style="width:14%">Monitoring</th>
                        <th style="width:18%">Current State</th>
                        <th>Last Checked</th>
                    </tr>
                </thead>
                <tbody>
<?php foreach ($services as $svc): ?>
<?php
    $stateBadge = match ($svc['last_state']) {
        'up'    => 'bg-success',
        'down'  => 'bg-danger',
        'error' => 'bg-dark',
        default => 'bg-secondary',
    };
    $stateLabel = $svc['last_state'] ?? 'unknown';
?>
                    <tr>
                        <td class="fw-medium"><?= htmlspecialchars($svc['name']) ?></td>
                        <td class="font-monospace small">
                            <?= htmlspecialchars(strtoupper($svc['protocol'])) ?>
                            <span style="color:var(--app-text-muted)">/</span>
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
                        <td class="small" style="color:var(--app-text-muted)">
                            <?= $svc['last_check_at'] !== null
                                ? htmlspecialchars($svc['last_check_at'])
                                : '<span style="color:var(--app-text-muted)">Never</span>' ?>
                        </td>
                    </tr>
<?php endforeach; ?>
                </tbody>
            </table>
        </div>
<?php endif; ?>
    </div>
</div>

<!-- ── Service History (graphs per service) ───────────────────────────────── -->
<?php
$serviceChartData = [];
foreach ($serviceHistories ?? [] as $svcId => $history) {
    if (empty($history)) {
        continue;
    }
    $isUp = array_map(fn($r) => $r['status'] === 'up', $history);
    $serviceChartData[$svcId] = [
        'labels'      => array_column($history, 'checked_at'),
        'latency'     => array_map(fn($r) => $r['latency_ms'], $history),
        // Status colors are semantic (green=up, red=down) and intentionally fixed.
        'pointColors' => array_map(fn($u) => $u ? 'rgba(25,135,84,0.9)' : 'rgba(220,53,69,0.9)', $isUp),
        'barColors'   => array_map(fn($u) => $u ? 'rgba(25,135,84,0.75)' : 'rgba(220,53,69,0.75)', $isUp),
    ];
}
$hasServiceHistory = !empty($serviceChartData);
?>
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
                <span class="font-monospace ms-1" style="color:var(--app-text-muted)">:<?= (int) $svc['port'] ?></span>
                <span class="ms-2" style="color:var(--app-text-muted)">&mdash; last <?= count($serviceChartData[$svcId]['labels']) ?> checks</span>
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

<!-- ── Monitoring History (device-level graphs) ───────────────────────────── -->
<?php if (!empty($historySeries)): ?>
<?php
$chartLabels   = array_column($historySeries, 'checked_at');
$chartLatency  = array_map(fn($r) => $r['latency_ms'], $historySeries);
$chartIsOnline = array_map(fn($r) => $r['status'] === 'online', $historySeries);
// Status colors are semantic and intentionally fixed.
$pointColors = array_map(
    fn($online) => $online ? 'rgba(25, 135, 84, 0.9)' : 'rgba(220, 53, 69, 0.9)',
    $chartIsOnline
);
$barColors = array_map(
    fn($online) => $online ? 'rgba(25, 135, 84, 0.75)' : 'rgba(220, 53, 69, 0.75)',
    $chartIsOnline
);

$jsonLabels      = json_encode($chartLabels,  JSON_UNESCAPED_UNICODE);
$jsonLatency     = json_encode($chartLatency, JSON_UNESCAPED_UNICODE);
$jsonPointColors = json_encode($pointColors,  JSON_UNESCAPED_UNICODE);
$jsonBarColors   = json_encode($barColors,    JSON_UNESCAPED_UNICODE);
?>
<div class="card mb-3">
    <div class="card-body">
        <h6 class="section-label mb-3">Monitoring History</h6>

        <div class="mb-3">
            <p class="small mb-2" style="color:var(--app-text-muted)">
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
            <p class="small mb-2" style="color:var(--app-text-muted)">Status timeline</p>
            <div style="position:relative;height:40px">
                <canvas id="chart-status"></canvas>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- ── Recent Check History ───────────────────────────────────────────────── -->
<div class="card">
    <div class="card-body">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h6 class="section-label mb-0">Recent Check History</h6>
            <span class="small" style="color:var(--app-text-muted)">
                <?= $checkCount === 1 ? '1 result' : "{$checkCount} results" ?> (last 50)
            </span>
        </div>

<?php if (empty($recentChecks)): ?>
        <p class="small mb-0" style="color:var(--app-text-muted)">
            No check history yet. Run <code>php scripts/monitor.php</code> to record the first check.
        </p>
<?php else: ?>
        <div class="table-responsive">
            <table id="tbl-recent-checks" class="table table-sm table-hover align-middle mb-0">
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
        'error'   => 'bg-dark',
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
                                : '<span style="color:var(--app-text-muted)">—</span>' ?>
                        </td>
                        <td class="small" style="color:var(--app-text-muted)">
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

<?php if (!empty($historySeries) || $hasServiceHistory): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<?php endif; ?>

<script>
window.addEventListener('DOMContentLoaded', function () {

    // ── Read theme tokens from CSS custom properties ──────────────────────
    // Allows charts to adapt to dark/light theme without hardcoded colours.
    var cs          = getComputedStyle(document.documentElement);
    var colorMuted  = cs.getPropertyValue('--app-text-muted').trim();
    var colorBorder = cs.getPropertyValue('--app-border').trim();
    var colorPrimary = cs.getPropertyValue('--app-primary').trim();

    // ── DataTables ────────────────────────────────────────────────────────
<?php if (!empty($openAlerts)): ?>
    $('#tbl-open-alerts').DataTable({
        pageLength:  5,
        lengthMenu:  [5, 10, 25],
        order:       [[3, 'desc']],   // sort by Last Seen descending
        columnDefs:  [{ orderable: false, targets: 4 }],
        responsive:  true
    });
<?php endif; ?>

<?php if (!empty($recentChecks)): ?>
    $('#tbl-recent-checks').DataTable({
        pageLength:  25,
        lengthMenu:  [10, 25, 50],
        order:       [[0, 'desc']],   // sort by Checked At descending
        responsive:  true
    });
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
