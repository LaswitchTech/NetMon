<?php
/**
 * Device Detail content fragment — read-only.
 *
 * Variables available (set by DeviceController::show() before ob_start):
 *   $device       (array)  — row from DeviceRepository::findById(); keys:
 *                            id, name, host, address, description, status,
 *                            last_check_at, created_at
 *   $interfaces   (array)  — from DeviceRepository::findInterfacesWithAddresses();
 *                            each entry: id, name, mac_address, is_management,
 *                            description, addresses[]
 *   $recentChecks  (array)  — from DeviceCheckRepository::findRecentByDevice();
 *                             each entry: id, checked_at, status, latency_ms, message
 *   $historySeries    (array)  — from DeviceCheckRepository::findHistoryByDevice();
 *                               each entry: checked_at, status, latency_ms (ASC order, for graphs)
 *   $services         (array)  — from ServiceCheckRepository::findByDevice();
 *                               each entry: id, name, protocol, port, monitoring_enabled,
 *                               expected_state, last_state, last_check_at
 *   $serviceHistories (array)  — keyed by service id; each value is an array of rows from
 *                               ServiceCheckRepository::findHistoryByService() (ASC order);
 *                               each row: checked_at, status, latency_ms
 *   $user         (array)
 *   $permissions  (array)
 *   $appName      (string)
 *   $displayName  (string)
 */

$statusBadge = match ($device['status']) {
    'online'   => ['class' => 'bg-success', 'icon' => 'bi-check-circle-fill'],
    'offline'  => ['class' => 'bg-danger',  'icon' => 'bi-x-circle-fill'],
    'degraded' => ['class' => 'bg-warning text-dark', 'icon' => 'bi-exclamation-circle-fill'],
    default    => ['class' => 'bg-secondary', 'icon' => 'bi-question-circle-fill'],
};

$checkCount = count($recentChecks);
?>

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
        <p class="text-muted small mb-0">
            Device detail &mdash; read only
        </p>
    </div>
    <div class="d-flex gap-2">
        <a href="/devices/<?= (int) $device['id'] ?>/edit"
           class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-pencil me-1"></i>Edit
        </a>
    </div>
</div>

<!-- Overview + Status row -->
<div class="row g-3 mb-3">

    <!-- Overview card -->
    <div class="col-md-8">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <h6 class="card-subtitle text-muted text-uppercase fw-semibold mb-3"
                    style="font-size:.7rem;letter-spacing:.07em">Overview</h6>
                <dl class="row mb-0 small">
                    <dt class="col-sm-4 text-muted fw-normal">Name</dt>
                    <dd class="col-sm-8 fw-medium mb-2"><?= htmlspecialchars($device['name']) ?></dd>

                    <dt class="col-sm-4 text-muted fw-normal">Management address</dt>
                    <dd class="col-sm-8 font-monospace mb-2"><?= htmlspecialchars($device['address']) ?></dd>

<?php if (!empty($device['description'])): ?>
                    <dt class="col-sm-4 text-muted fw-normal">Description</dt>
                    <dd class="col-sm-8 mb-2"><?= htmlspecialchars($device['description']) ?></dd>
<?php endif; ?>

                    <dt class="col-sm-4 text-muted fw-normal">Added</dt>
                    <dd class="col-sm-8 mb-0"><?= htmlspecialchars($device['created_at']) ?></dd>
                </dl>
            </div>
        </div>
    </div>

    <!-- Status card -->
    <div class="col-md-4">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex flex-column">
                <h6 class="card-subtitle text-muted text-uppercase fw-semibold mb-3"
                    style="font-size:.7rem;letter-spacing:.07em">Current Status</h6>
                <div class="d-flex align-items-center gap-2 mb-3">
                    <span class="badge <?= $statusBadge['class'] ?> d-flex align-items-center gap-1 px-2 py-2"
                          style="font-size:.8rem">
                        <i class="bi <?= $statusBadge['icon'] ?>"></i>
                        <?= htmlspecialchars($device['status']) ?>
                    </span>
                </div>
                <dl class="row mb-0 small mt-auto">
                    <dt class="col-12 text-muted fw-normal mb-1">Last check</dt>
                    <dd class="col-12 font-monospace mb-0">
<?php if ($device['last_check_at'] !== null): ?>
                        <?= htmlspecialchars($device['last_check_at']) ?>
<?php else: ?>
                        <span class="text-muted">Never checked</span>
<?php endif; ?>
                    </dd>
                </dl>
            </div>
        </div>
    </div>

</div>

<!-- Interfaces & Addresses -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <h6 class="card-subtitle text-muted text-uppercase fw-semibold mb-3"
            style="font-size:.7rem;letter-spacing:.07em">Interfaces &amp; Addresses</h6>

<?php if (empty($interfaces)): ?>
        <p class="text-muted small mb-0">No interface records found.</p>
<?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
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
                        <td class="font-monospace small text-muted">
                            <?= $iface['mac_address'] !== null
                                ? htmlspecialchars($iface['mac_address'])
                                : '<span class="text-muted">—</span>' ?>
                        </td>
                        <td>
<?php if ($iface['is_management']): ?>
                            <span class="badge bg-primary-subtle text-primary border border-primary-subtle"
                                  style="font-size:.7rem">management</span>
<?php else: ?>
                            <span class="text-muted small">—</span>
<?php endif; ?>
                        </td>
                        <td class="font-monospace small">
<?php if (empty($iface['addresses'])): ?>
                            <span class="text-muted">—</span>
<?php else: ?>
<?php foreach ($iface['addresses'] as $addr): ?>
                            <div>
                                <?= htmlspecialchars($addr['address']) ?>
                                <span class="text-muted">(<?= htmlspecialchars($addr['family']) ?>)</span>
<?php if ($addr['is_primary']): ?>
                                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle ms-1"
                                      style="font-size:.65rem">primary</span>
<?php endif; ?>
                            </div>
<?php endforeach; ?>
<?php endif; ?>
                        </td>
                        <td class="small text-muted">
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

<!-- Monitored Services -->
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <h6 class="card-subtitle text-muted text-uppercase fw-semibold mb-3"
            style="font-size:.7rem;letter-spacing:.07em">Monitored Services</h6>

<?php if (empty($services)): ?>
        <p class="text-muted small mb-0">
            No services configured for this device.
            Add services via <code>php scripts/seed.php MonitoredServiceSeed</code> (dev)
            or the service management UI once available.
        </p>
<?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
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
                            <span class="text-muted">/</span>
                            <?= (int) $svc['port'] ?>
                        </td>
                        <td>
<?php if ($svc['monitoring_enabled']): ?>
                            <span class="badge bg-success-subtle text-success border border-success-subtle"
                                  style="font-size:.7rem">enabled</span>
<?php else: ?>
                            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle"
                                  style="font-size:.7rem">disabled</span>
<?php endif; ?>
                        </td>
                        <td>
                            <span class="badge <?= $stateBadge ?>"><?= htmlspecialchars($stateLabel) ?></span>
                        </td>
                        <td class="small text-muted">
                            <?= $svc['last_check_at'] !== null
                                ? htmlspecialchars($svc['last_check_at'])
                                : '<span class="text-muted">Never</span>' ?>
                        </td>
                    </tr>
<?php endforeach; ?>
                </tbody>
            </table>
        </div>
<?php endif; ?>
    </div>
</div>

<!-- Service History (graphs per service) -->
<?php
// Build per-service chart data from $serviceHistories (keyed by service id).
// Service status values are 'up' / 'down' / 'error' — distinct from device 'online'/'offline'.
$serviceChartData = [];
foreach ($serviceHistories ?? [] as $svcId => $history) {
    if (empty($history)) {
        continue;
    }
    $isUp = array_map(fn($r) => $r['status'] === 'up', $history);
    $serviceChartData[$svcId] = [
        'labels'      => array_column($history, 'checked_at'),
        'latency'     => array_map(fn($r) => $r['latency_ms'], $history),
        'pointColors' => array_map(fn($u) => $u ? 'rgba(25,135,84,0.9)' : 'rgba(220,53,69,0.9)', $isUp),
        'barColors'   => array_map(fn($u) => $u ? 'rgba(25,135,84,0.75)' : 'rgba(220,53,69,0.75)', $isUp),
    ];
}
$hasServiceHistory = !empty($serviceChartData);
?>
<?php if ($hasServiceHistory): ?>
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <h6 class="card-subtitle text-muted text-uppercase fw-semibold mb-3"
            style="font-size:.7rem;letter-spacing:.07em">Service History</h6>

<?php foreach ($services as $svc): ?>
<?php $svcId = (int) $svc['id']; ?>
<?php if (!isset($serviceChartData[$svcId])): ?>
<?php continue; ?>
<?php endif; ?>
        <div class="mb-4">
            <!-- Service label -->
            <p class="small fw-medium mb-2">
                <?= htmlspecialchars($svc['name']) ?>
                <span class="font-monospace text-muted ms-1">:<?= (int) $svc['port'] ?></span>
                <span class="text-muted ms-2">&mdash; last <?= count($serviceChartData[$svcId]['labels']) ?> checks</span>
                <span class="ms-2">
                    <span class="badge" style="background:rgba(25,135,84,0.75);font-size:.62rem">Up</span>
                    <span class="badge ms-1" style="background:rgba(220,53,69,0.75);font-size:.62rem">Down</span>
                </span>
            </p>

            <!-- Latency mini-chart -->
            <div style="position:relative;height:110px" class="mb-1">
                <canvas id="chart-svc-latency-<?= $svcId ?>"></canvas>
            </div>

            <!-- Status strip -->
            <div style="position:relative;height:28px">
                <canvas id="chart-svc-status-<?= $svcId ?>"></canvas>
            </div>
        </div>
<?php endforeach; ?>

    </div>
</div>
<?php endif; ?>

<!-- Monitoring History (graphs) -->
<?php if (!empty($historySeries)): ?>
<?php
// Prepare data series for Chart.js.
// Labels: full datetime strings — Chart.js maxTicksLimit keeps the x-axis readable.
$chartLabels   = array_column($historySeries, 'checked_at');
$chartLatency  = array_map(fn($r) => $r['latency_ms'], $historySeries);  // int|null — nulls become JSON null
$chartIsOnline = array_map(fn($r) => $r['status'] === 'online', $historySeries);

// Per-point colors: green dot when online, red dot when offline/unknown.
$pointColors = array_map(
    fn($online) => $online ? 'rgba(25, 135, 84, 0.9)' : 'rgba(220, 53, 69, 0.9)',
    $chartIsOnline
);
// Status bar colors (full opacity for the timeline strip).
$barColors = array_map(
    fn($online) => $online ? 'rgba(25, 135, 84, 0.75)' : 'rgba(220, 53, 69, 0.75)',
    $chartIsOnline
);

$jsonLabels      = json_encode($chartLabels,  JSON_UNESCAPED_UNICODE);
$jsonLatency     = json_encode($chartLatency, JSON_UNESCAPED_UNICODE);   // nulls preserved
$jsonPointColors = json_encode($pointColors,  JSON_UNESCAPED_UNICODE);
$jsonBarColors   = json_encode($barColors,    JSON_UNESCAPED_UNICODE);
?>
<div class="card border-0 shadow-sm mb-3">
    <div class="card-body">
        <h6 class="card-subtitle text-muted text-uppercase fw-semibold mb-3"
            style="font-size:.7rem;letter-spacing:.07em">Monitoring History</h6>

        <!-- Latency line chart -->
        <div class="mb-3">
            <p class="text-muted small mb-2">Latency (ms) &mdash; last <?= count($historySeries) ?> checks</p>
            <div style="position:relative;height:180px">
                <canvas id="chart-latency"></canvas>
            </div>
        </div>

        <!-- Status timeline strip -->
        <div>
            <p class="text-muted small mb-2">Status timeline
                <span class="ms-2">
                    <span class="badge" style="background:rgba(25,135,84,0.75);font-size:.65rem">Online</span>
                    <span class="badge ms-1" style="background:rgba(220,53,69,0.75);font-size:.65rem">Offline</span>
                </span>
            </p>
            <div style="position:relative;height:40px">
                <canvas id="chart-status"></canvas>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<!-- Recent check history -->
<div class="card border-0 shadow-sm">
    <div class="card-body">
        <div class="d-flex align-items-center justify-content-between mb-3">
            <h6 class="card-subtitle text-muted text-uppercase fw-semibold mb-0"
                style="font-size:.7rem;letter-spacing:.07em">Recent Check History</h6>
            <span class="text-muted small">
                <?= $checkCount === 1 ? '1 result' : "{$checkCount} results" ?> (last 50)
            </span>
        </div>

<?php if (empty($recentChecks)): ?>
        <p class="text-muted small mb-0">
            No check history yet. Run <code>php scripts/monitor.php</code> to record the first check.
        </p>
<?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm table-hover align-middle mb-0">
                <thead class="table-light">
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

<?php if (!empty($historySeries) || $hasServiceHistory): ?>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
(function () {

    // ── Shared helpers ────────────────────────────────────────────────────
    // Both device-level and service-level charts use the same config shape.
    // The only differences are canvas ID, data arrays, and tooltip wording.

    function makeLatencyChart(canvasId, labels, latency, pointColors, offlineLabel) {
        new Chart(document.getElementById(canvasId), {
            type: 'line',
            data: {
                labels: labels,
                datasets: [{
                    data: latency,
                    borderColor: 'rgba(13, 110, 253, 0.8)',
                    borderWidth: 1.5,
                    pointRadius: 3,
                    pointHoverRadius: 5,
                    pointBackgroundColor: pointColors,
                    pointBorderColor: pointColors,
                    fill: false,
                    tension: 0.2,
                    spanGaps: false   // line breaks where latency is null
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
                        ticks: { maxTicksLimit: 8, maxRotation: 45, font: { size: 10 }, color: '#6c757d' },
                        grid:  { color: 'rgba(0,0,0,0.04)' }
                    },
                    y: {
                        beginAtZero: true,
                        ticks: { font: { size: 10 }, color: '#6c757d', callback: function (v) { return v + ' ms'; } },
                        grid: { color: 'rgba(0,0,0,0.04)' }
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

})();
</script>
<?php endif; ?>
