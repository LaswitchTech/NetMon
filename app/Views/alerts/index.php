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

$count = count($alerts);

/**
 * Format an alert_type slug into a readable label.
 * e.g. 'device_offline' → 'Device offline'
 */
$formatType = static function (string $type): string {
    return ucfirst(str_replace('_', ' ', $type));
};
?>

<!-- Page heading -->
<div class="mb-4">
    <h1 class="h4 fw-semibold mb-1">Alerts</h1>
    <p class="text-muted mb-0 small">
        Stateful alert records. Each row represents one unresolved or recently resolved condition.
    </p>
</div>

<!-- Toolbar -->
<div class="d-flex align-items-center justify-content-between mb-3">
    <span class="text-muted small">
        <?php if ($filter === 'open'): ?>
            <?= $count === 1 ? '1 open alert' : "{$count} open alerts" ?>
        <?php else: ?>
            <?= $count === 1 ? '1 alert' : "{$count} alerts" ?> (most recent 100)
        <?php endif; ?>
    </span>
    <div class="btn-group btn-group-sm" role="group" aria-label="Alert filter">
        <a href="/alerts?filter=open"
           class="btn <?= $filter === 'open' ? 'btn-primary' : 'btn-outline-secondary' ?>">
            Open
        </a>
        <a href="/alerts?filter=all"
           class="btn <?= $filter === 'all' ? 'btn-primary' : 'btn-outline-secondary' ?>">
            All
        </a>
    </div>
</div>

<!-- Alerts table -->
<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th class="ps-4" style="width:18%">Device</th>
                    <th style="width:15%">Type</th>
                    <th style="width:13%">Service</th>
                    <th style="width:10%">Status</th>
                    <th style="width:7%" class="text-end">Count</th>
                    <th style="width:14%">First seen</th>
                    <th style="width:14%">Last seen</th>
                    <th style="width:9%">Last notified</th>
                </tr>
            </thead>
            <tbody>
<?php if (empty($alerts)): ?>
                <tr>
                    <td colspan="8" class="text-center py-5 text-muted">
                        <i class="bi bi-bell-slash opacity-25" style="font-size: 2.5rem; display: block; margin-bottom: .75rem"></i>
                        <?php if ($filter === 'open'): ?>
                            No open alerts &mdash; all devices are healthy.
                        <?php else: ?>
                            No alerts recorded yet.
                        <?php endif; ?>
                    </td>
                </tr>
<?php else: ?>
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
                    <td class="ps-4 fw-medium">
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
<?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
