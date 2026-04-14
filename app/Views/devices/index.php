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
    <a href="/devices/create" class="btn btn-sm btn-primary">
        <i class="bi bi-plus-lg me-1"></i>Add Device
    </a>
</div>

<!-- Device table -->
<div class="card border-0 shadow-sm">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th class="ps-4" style="width:28%">Name</th>
                    <th style="width:22%">Host / IP</th>
                    <th style="width:18%">Status</th>
                    <th style="width:20%">Last Check</th>
                    <th style="width:12%" class="text-end pe-4">Actions</th>
                </tr>
            </thead>
            <tbody>
<?php if (empty($devices)): ?>
                <tr>
                    <td colspan="5" class="text-center py-5 text-muted">
                        <i class="bi bi-cpu opacity-25" style="font-size: 2.5rem; display: block; margin-bottom: .75rem"></i>
                        No devices configured yet.
                        <br>
                        <a href="/devices/create" class="small">Add your first device</a>
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
    $deviceId = (int) $device['id'];
?>
                <tr>
                    <td class="ps-4 fw-medium">
                        <a href="/devices/<?= $deviceId ?>" class="text-decoration-none text-reset">
                            <?= htmlspecialchars($device['name']) ?>
                        </a>
                    </td>
                    <td class="font-monospace small"><?= htmlspecialchars($device['address']) ?></td>
                    <td>
                        <span class="badge <?= $badgeClass ?>">
                            <?= htmlspecialchars($device['status']) ?>
                        </span>
                    </td>
                    <td class="text-muted small"><?= $lastCheck ?></td>
                    <td class="text-end pe-4">
                        <a href="/devices/<?= $deviceId ?>/edit"
                           class="btn btn-sm btn-outline-secondary me-1"
                           title="Edit">
                            <i class="bi bi-pencil"></i>
                        </a>
                        <button type="button"
                                class="btn btn-sm btn-outline-danger"
                                title="Delete"
                                data-bs-toggle="modal"
                                data-bs-target="#deleteModal"
                                data-device-id="<?= $deviceId ?>"
                                data-device-name="<?= htmlspecialchars($device['name'], ENT_QUOTES) ?>">
                            <i class="bi bi-trash"></i>
                        </button>
                    </td>
                </tr>
<?php endforeach; ?>
<?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Delete confirmation modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header border-0 pb-0">
                <h5 class="modal-title" id="deleteModalLabel">Remove device?</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body pt-2 pb-1">
                <p class="text-muted small mb-0">
                    <strong id="deleteDeviceName"></strong> will be removed from monitoring.
                    This action can be undone by an administrator.
                </p>
            </div>
            <div class="modal-footer border-0 pt-2">
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-dismiss="modal">Cancel</button>
                <form id="deleteForm" method="post" action="" class="d-inline">
                    <button type="submit" class="btn btn-sm btn-danger">Remove</button>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    var modal = document.getElementById('deleteModal');
    modal.addEventListener('show.bs.modal', function (event) {
        var btn    = event.relatedTarget;
        var id     = btn.getAttribute('data-device-id');
        var name   = btn.getAttribute('data-device-name');
        document.getElementById('deleteDeviceName').textContent = name;
        document.getElementById('deleteForm').setAttribute('action', '/devices/' + id + '/delete');
    });
})();
</script>
