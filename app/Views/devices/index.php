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

?>

<!-- Page heading -->
<div class="mb-4">
    <h1 class="h4 fw-semibold mb-1">Devices</h1>
    <p class="text-muted mb-0 small">
        Monitored network devices. Add devices to start tracking their status and availability.
    </p>
</div>

<!-- Device table -->
<div class="card">
    <div class="table-responsive p-3">
        <table id="tbl-devices" class="table table-hover align-middle mb-0 w-100">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Host / IP</th>
                    <th>Status</th>
                    <th>Last Check</th>
                    <th class="text-end">Actions</th>
                </tr>
            </thead>
            <tbody>
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
                    <td class="fw-medium">
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
                    <td class="text-end">
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
window.addEventListener('DOMContentLoaded', function () {
    // DataTables
    // NOTE: do NOT use initComplete to inject buttons — in DataTables 1.13.x
    // `this` inside initComplete is settings.oApi (internal _fn* functions),
    // not the public API.  this.table() throws TypeError.  Capture the dt
    // return value and call dt.buttons().container() instead.
    var dt = NetMon.dt.init('#tbl-devices', {
        pageLength : 25,
        order      : [[0, 'asc']],
        columnDefs : [{ orderable: false, targets: 4 }],
        language   : {
            emptyTable : 'No devices configured yet.',
        },
    });

    // Inject Add Device link into the DataTables buttons area (top-left)
    dt.buttons().container().prepend(
        '<a href="/devices/create" class="btn btn-sm btn-primary me-1">' +
        '<i class="bi bi-plus-lg me-1"></i>Add Device</a>'
    );

    // Delete modal — populate from data attributes
    var modal = document.getElementById('deleteModal');
    modal.addEventListener('show.bs.modal', function (event) {
        var btn  = event.relatedTarget;
        var id   = btn.getAttribute('data-device-id');
        var name = btn.getAttribute('data-device-name');
        document.getElementById('deleteDeviceName').textContent = name;
        document.getElementById('deleteForm').setAttribute('action', '/devices/' + id + '/delete');
    });
});
</script>
