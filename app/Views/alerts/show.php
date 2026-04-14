<?php
/**
 * Alert detail content fragment.
 *
 * Variables available (set by AlertController::show before ob_start):
 *   $user          (array)   — safe user record from the principal
 *   $permissions   (array)   — permission names for the authenticated user
 *   $appName       (string)  — application name from config
 *   $displayName   (string)  — display_name if set, otherwise username
 *   $alert         (array)   — row from AlertRepository::findById (includes device_name, service_name, service_port)
 *   $notifications (array)   — rows from NotificationRepository::findRecentByAlert
 */

$alertId = (int) $alert['id'];
$isOpen  = $alert['status'] === 'open';

$statusBadge = match ($alert['status']) {
    'open'         => ['class' => 'bg-danger',              'label' => 'Open'],
    'resolved'     => ['class' => 'bg-success',             'label' => 'Resolved'],
    'acknowledged' => ['class' => 'bg-warning text-dark',   'label' => 'Acknowledged'],
    'suppressed'   => ['class' => 'bg-secondary',           'label' => 'Suppressed'],
    default        => ['class' => 'bg-secondary',           'label' => htmlspecialchars($alert['status'])],
};

$formatType = static function (string $type): string {
    return ucfirst(str_replace('_', ' ', $type));
};

$deviceName = ($alert['device_name'] ?? '') !== ''
    ? htmlspecialchars($alert['device_name'])
    : 'Device #' . (int) $alert['device_id'];

$hasService = isset($alert['service_name']) && $alert['service_name'] !== null;
?>

<!-- Breadcrumb -->
<nav aria-label="breadcrumb" class="mb-4">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="/alerts">Alerts</a></li>
        <li class="breadcrumb-item active" aria-current="page">Alert #<?= $alertId ?></li>
    </ol>
</nav>

<!-- Page heading -->
<div class="mb-4">
    <h1 class="h4 fw-semibold mb-1">
        Alert #<?= $alertId ?>
        <span class="badge <?= $statusBadge['class'] ?> ms-2 fs-6 align-middle"><?= $statusBadge['label'] ?></span>
    </h1>
    <p class="text-muted mb-0 small">
        <?= htmlspecialchars($formatType($alert['alert_type'])) ?>
        &mdash; <?= $deviceName ?>
        <?php if ($hasService): ?>
            / <?= htmlspecialchars($alert['service_name']) ?>:<?= (int) $alert['service_port'] ?>
        <?php endif; ?>
    </p>
</div>

<div class="row g-4">

    <!-- Overview card -->
    <div class="col-lg-7">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent border-bottom-0 pt-3 pb-2 px-4">
                <h2 class="h6 fw-semibold mb-0">Overview</h2>
            </div>
            <div class="card-body px-4 pb-4">
                <dl class="row mb-0" style="row-gap:.5rem">

                    <dt class="col-sm-4 text-muted small fw-normal">Device</dt>
                    <dd class="col-sm-8 mb-0">
                        <a href="/devices/<?= (int) $alert['device_id'] ?>" class="text-decoration-none">
                            <?= $deviceName ?>
                        </a>
                    </dd>

                    <?php if ($hasService): ?>
                    <dt class="col-sm-4 text-muted small fw-normal">Service</dt>
                    <dd class="col-sm-8 mb-0">
                        <?= htmlspecialchars($alert['service_name']) ?>
                        <span class="text-muted ms-1 font-monospace small">:<?= (int) $alert['service_port'] ?></span>
                    </dd>
                    <?php endif; ?>

                    <dt class="col-sm-4 text-muted small fw-normal">Type</dt>
                    <dd class="col-sm-8 mb-0"><?= htmlspecialchars($formatType($alert['alert_type'])) ?></dd>

                    <dt class="col-sm-4 text-muted small fw-normal">Occurrences</dt>
                    <dd class="col-sm-8 mb-0 font-monospace"><?= (int) $alert['occurrence_count'] ?></dd>

                    <dt class="col-sm-4 text-muted small fw-normal">First seen</dt>
                    <dd class="col-sm-8 mb-0 small"><?= htmlspecialchars($alert['first_seen_at']) ?></dd>

                    <dt class="col-sm-4 text-muted small fw-normal">Last seen</dt>
                    <dd class="col-sm-8 mb-0 small"><?= htmlspecialchars($alert['last_seen_at']) ?></dd>

                    <dt class="col-sm-4 text-muted small fw-normal">Last notified</dt>
                    <dd class="col-sm-8 mb-0 small">
                        <?php if ($alert['last_notified_at'] !== null): ?>
                            <?= htmlspecialchars($alert['last_notified_at']) ?>
                        <?php else: ?>
                            <span class="text-muted">Never</span>
                        <?php endif; ?>
                    </dd>

                    <?php if ($alert['resolved_at'] !== null): ?>
                    <dt class="col-sm-4 text-muted small fw-normal">Resolved at</dt>
                    <dd class="col-sm-8 mb-0 small"><?= htmlspecialchars($alert['resolved_at']) ?></dd>
                    <?php endif; ?>

                </dl>
            </div>
        </div>
    </div>

    <!-- Status & actions card -->
    <div class="col-lg-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-header bg-transparent border-bottom-0 pt-3 pb-2 px-4">
                <h2 class="h6 fw-semibold mb-0">Status &amp; Actions</h2>
            </div>
            <div class="card-body px-4 pb-4">

                <div class="mb-3">
                    <span class="d-inline-block text-muted small me-2">Current status:</span>
                    <span class="badge <?= $statusBadge['class'] ?>"><?= $statusBadge['label'] ?></span>
                </div>

                <?php if ($isOpen): ?>
                <p class="small text-muted mb-3">
                    This alert is currently open. You can acknowledge it (mark as seen, still active)
                    or suppress it (silence without resolving).
                </p>
                <div class="d-flex gap-2 flex-wrap">
                    <form method="POST" action="/alerts/<?= $alertId ?>/acknowledge">
                        <button type="submit" class="btn btn-sm btn-warning">
                            <i class="bi bi-eye me-1"></i>Acknowledge
                        </button>
                    </form>
                    <form method="POST" action="/alerts/<?= $alertId ?>/suppress">
                        <button type="submit" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-bell-slash me-1"></i>Suppress
                        </button>
                    </form>
                </div>
                <?php elseif ($alert['status'] === 'acknowledged'): ?>
                <p class="small text-muted mb-0">
                    This alert has been acknowledged. It will resolve automatically when the condition clears.
                </p>
                <?php elseif ($alert['status'] === 'suppressed'): ?>
                <p class="small text-muted mb-0">
                    This alert is suppressed. Notifications will not fire until it resolves and re-opens.
                </p>
                <?php else: ?>
                <p class="small text-muted mb-0">
                    This alert has been resolved. No further action is required.
                </p>
                <?php endif; ?>

            </div>
        </div>
    </div>

</div>

<!-- Notification history -->
<div class="mt-4">
    <div class="card border-0 shadow-sm">
        <div class="card-header bg-transparent border-bottom-0 pt-3 pb-2 px-4">
            <h2 class="h6 fw-semibold mb-0">Notification History</h2>
        </div>
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th class="ps-4" style="width:20%">Sent at</th>
                        <th style="width:15%">Channel</th>
                        <th style="width:15%">Type</th>
                        <th style="width:10%">Status</th>
                        <th>Message</th>
                    </tr>
                </thead>
                <tbody>
<?php if (empty($notifications)): ?>
                    <tr>
                        <td colspan="5" class="text-center py-4 text-muted small">
                            No notifications sent for this alert yet.
                        </td>
                    </tr>
<?php else: ?>
<?php foreach ($notifications as $n): ?>
<?php
    $nStatusBadge = $n['status'] === 'sent'
        ? ['class' => 'bg-success', 'label' => 'Sent']
        : ['class' => 'bg-danger',  'label' => 'Failed'];
?>
                    <tr>
                        <td class="ps-4 small"><?= htmlspecialchars($n['sent_at']) ?></td>
                        <td class="small"><?= htmlspecialchars($n['channel']) ?></td>
                        <td class="small"><?= htmlspecialchars(ucfirst($n['notification_type'])) ?></td>
                        <td>
                            <span class="badge <?= $nStatusBadge['class'] ?>"><?= $nStatusBadge['label'] ?></span>
                        </td>
                        <td class="small text-muted">
                            <?= $n['message'] !== null ? htmlspecialchars($n['message']) : '&mdash;' ?>
                        </td>
                    </tr>
<?php endforeach; ?>
<?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
