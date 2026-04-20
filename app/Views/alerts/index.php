<?php
/**
 * Alerts content fragment.
 *
 * Variables available (set by AlertController before ob_start):
 *   $user        (array)   — safe user record from the principal
 *   $permissions (array)   — permission names for the authenticated user
 *   $appName     (string)  — application name from config
 *   $displayName (string)  — display_name if set, otherwise username
 *   $alerts      (array)   — rows from AlertRepository; each row includes device_name
 *   $filter      (string)  — active filter: 'open' or 'all'
 */

/**
 * Format an alert_type slug into a readable label.
 * e.g. 'device_offline' → 'Device offline'
 */
$formatType = static function (string $type): string {
    return ucfirst(str_replace('_', ' ', $type));
};

$emptyMsg = $filter === 'open'
    ? 'No open alerts &mdash; all devices are healthy.'
    : 'No alerts recorded yet.';
?>

<!-- Page heading -->
<div class="mb-4">
    <h1 class="h4 fw-semibold mb-1">Alerts</h1>
    <p class="text-muted mb-0 small">
        Stateful alert records. Each row represents one unresolved or recently resolved condition.
    </p>
</div>

<!-- Alerts table -->
<div class="card">
    <div class="table-responsive p-3">
        <table id="tbl-alerts" class="table table-hover align-middle mb-0 w-100">
            <thead>
                <tr>
                    <th>Device</th>
                    <th>Type</th>
                    <th>Service</th>
                    <th>Status</th>
                    <th class="text-end">Count</th>
                    <th>First seen</th>
                    <th>Last seen</th>
                    <th>Last notified</th>
                </tr>
            </thead>
            <tbody>
<?php foreach ($alerts as $alert): ?>
<?php
    $statusBadge = match ($alert['status']) {
        'open'         => ['class' => 'bg-danger',   'label' => 'Open'],
        'resolved'     => ['class' => 'bg-success',  'label' => 'Resolved'],
        'acknowledged' => ['class' => 'bg-warning text-dark', 'label' => 'Ack\'d'],
        'suppressed'   => ['class' => 'bg-secondary','label' => 'Suppressed'],
        default        => ['class' => 'bg-secondary','label' => htmlspecialchars($alert['status'])],
    };

    $deviceLabel   = ($alert['device_name'] ?? '') !== ''
        ? htmlspecialchars($alert['device_name'])
        : 'Device #' . (int) $alert['device_id'];

    $hasService    = isset($alert['service_name']) && $alert['service_name'] !== null;

    $serviceCell   = $hasService
        ? htmlspecialchars($alert['service_name']) . '<span class="text-muted font-monospace ms-1 small">:' . (int) $alert['service_port'] . '</span>'
        : '<span class="text-muted">&mdash;</span>';

    $lastNotified  = $alert['last_notified_at'] !== null
        ? htmlspecialchars($alert['last_notified_at'])
        : '<span class="text-muted">Never</span>';
?>
                <tr>
                    <td class="fw-medium">
                        <a href="/alerts/<?= (int) $alert['id'] ?>" class="text-decoration-none">
                            <?= $deviceLabel ?>
                        </a>
                    </td>
                    <td class="small"><?= htmlspecialchars($formatType($alert['alert_type'])) ?></td>
                    <td class="small"><?= $serviceCell ?></td>
                    <td>
                        <span class="badge <?= $statusBadge['class'] ?>">
                            <?= $statusBadge['label'] ?>
                        </span>
                    </td>
                    <td class="text-end font-monospace small"><?= (int) $alert['occurrence_count'] ?></td>
                    <td class="text-muted small"><?= htmlspecialchars($alert['first_seen_at']) ?></td>
                    <td class="text-muted small"><?= htmlspecialchars($alert['last_seen_at']) ?></td>
                    <td class="small"><?= $lastNotified ?></td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
(function () {
    var alertFilter = <?= json_encode($filter) ?>;

    window.addEventListener('DOMContentLoaded', function () {
        // NOTE: do NOT use initComplete to inject buttons — in DataTables 1.13.x
        // `this` inside initComplete is settings.oApi (internal _fn* functions),
        // not the public API.  this.table() throws TypeError.  Capture the dt
        // return value and call dt.buttons().container() instead.
        var dt = NetMon.dt.init('#tbl-alerts', {
            pageLength : 25,
            order      : [[6, 'desc']],
            language   : { emptyTable: '<?= addslashes($emptyMsg) ?>' },
        });

        // Inject Open / All filter toggle into the DataTables buttons area (top-left)
        var openClass = alertFilter === 'open' ? 'btn-primary' : 'btn-outline-secondary';
        var allClass  = alertFilter === 'all'  ? 'btn-primary' : 'btn-outline-secondary';
        dt.buttons().container().prepend(
            '<div class="btn-group btn-group-sm me-1" role="group" aria-label="Alert filter">' +
            '<a href="/alerts?filter=open" class="btn ' + openClass + '">Open</a>' +
            '<a href="/alerts?filter=all"  class="btn ' + allClass  + '">All</a>'  +
            '</div>'
        );
    });
}());
</script>
