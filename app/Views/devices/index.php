<?php
/**
 * Devices content fragment.
 *
 * Variables available (set by DeviceController before ob_start):
 *   $user        (array)   — safe user record from the principal
 *   $permissions (array)   — permission names for the authenticated user
 *   $appName     (string)  — application name from config
 *   $displayName (string)  — display_name if set, otherwise username
 *   $devices     (array)   — rows from DeviceRepository::findAll(); each row has
 *                            `address` (resolved from device_addresses, or devices.host fallback)
 */

$count = count($devices);
?>

<!-- Page heading -->
<div class="mb-4">
    <h1 class="h4 fw-semibold mb-1">Devices</h1>
    <p class="text-muted mb-0 small">
        Monitored network devices. Add devices to start tracking their status and availability.
    </p>
</div>

<!-- Toolbar -->
<div class="d-flex align-items-center justify-content-between mb-3">
    <span class="text-muted small">
        <?= $count === 1 ? '1 device' : "{$count} devices" ?>
    </span>
    <button class="btn btn-sm btn-primary" disabled title="Device management coming soon">
        <i class="bi bi-plus-lg me-1"></i>Add Device
    </button>
</div>

<!-- Device table -->
<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th class="ps-4" style="width:30%">Name</th>
                    <th style="width:25%">Host / IP</th>
                    <th style="width:20%">Status</th>
                    <th style="width:25%">Last Check</th>
                </tr>
            </thead>
            <tbody>
<?php if (empty($devices)): ?>
                <tr>
                    <td colspan="4" class="text-center py-5 text-muted">
                        <i class="bi bi-cpu opacity-25" style="font-size: 2.5rem; display: block; margin-bottom: .75rem"></i>
                        No devices configured yet.
                        <br>
                        <span class="small">Device management will be available in a future update.</span>
                    </td>
                </tr>
<?php else: ?>
<?php foreach ($devices as $device): ?>
<?php
    // Map status to a Bootstrap badge colour
    $badgeClass = match ($device['status']) {
        'online'  => 'bg-success',
        'offline' => 'bg-danger',
        default   => 'bg-secondary',
    };
    $lastCheck = $device['last_check_at'] !== null
        ? htmlspecialchars($device['last_check_at'])
        : '<span class="text-muted">—</span>';
?>
                <tr>
                    <td class="ps-4 fw-medium"><?= htmlspecialchars($device['name']) ?></td>
                    <td class="font-monospace small"><?= htmlspecialchars($device['address']) ?></td>
                    <td>
                        <span class="badge <?= $badgeClass ?>">
                            <?= htmlspecialchars($device['status']) ?>
                        </span>
                    </td>
                    <td class="text-muted small"><?= $lastCheck ?></td>
                </tr>
<?php endforeach; ?>
<?php endif; ?>
            </tbody>
        </table>
    </div>
</div>
