<?php
/**
 * Merge Device form — operator-driven only.
 *
 * Variables available (set by DeviceController::mergeForm / merge before ob_start):
 *   $device      (array)  — source device row (id, name, address, status, ...)
 *   $candidates  (array)  — active devices excluding source; each: {id, name}
 *   $errors      (array)  — validation errors keyed by field name
 *   $user        (array)
 *   $permissions (array)
 *   $appName     (string)
 *   $displayName (string)
 */
?>

<!-- Breadcrumb -->
<nav aria-label="breadcrumb" class="mb-3">
    <ol class="breadcrumb small mb-0">
        <li class="breadcrumb-item">
            <a href="/devices" class="text-decoration-none">Devices</a>
        </li>
        <li class="breadcrumb-item">
            <a href="/devices/<?= (int) $device['id'] ?>" class="text-decoration-none">
                <?= htmlspecialchars($device['name']) ?>
            </a>
        </li>
        <li class="breadcrumb-item active" aria-current="page">Merge</li>
    </ol>
</nav>

<!-- Page heading -->
<div class="mb-4">
    <h1 class="h4 fw-semibold mb-1">Merge Device</h1>
    <p class="text-muted small mb-0">
        Transfer all interfaces, services, and alerts from the source device into another device,
        then soft-delete the source. This action cannot be undone automatically.
    </p>
</div>

<!-- Warning banner -->
<div class="alert alert-warning d-flex align-items-start gap-2 mb-4" role="alert">
    <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1"></i>
    <div>
        <strong>This is a destructive operation.</strong>
        The source device will be permanently deactivated after the merge.
        All interfaces, IP addresses, monitored services, alerts, and discovery links
        will move to the target device. Monitoring history is preserved.
    </div>
</div>

<div class="row g-4">

    <!-- Source device summary -->
    <div class="col-md-5">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body">
                <h6 class="card-subtitle text-muted text-uppercase fw-semibold mb-3"
                    style="font-size:.7rem;letter-spacing:.07em">Source Device (will be deactivated)</h6>
                <dl class="row mb-0 small">
                    <dt class="col-sm-5 text-muted fw-normal">Name</dt>
                    <dd class="col-sm-7 fw-medium mb-2"><?= htmlspecialchars($device['name']) ?></dd>

                    <dt class="col-sm-5 text-muted fw-normal">Address</dt>
                    <dd class="col-sm-7 font-monospace mb-2"><?= htmlspecialchars($device['address'] ?? '—') ?></dd>

                    <dt class="col-sm-5 text-muted fw-normal">Status</dt>
                    <dd class="col-sm-7 mb-0">
                        <?php
                        $badge = match ($device['status']) {
                            'online'   => 'bg-success',
                            'offline'  => 'bg-danger',
                            'degraded' => 'bg-warning text-dark',
                            default    => 'bg-secondary',
                        };
                        ?>
                        <span class="badge <?= $badge ?>"><?= htmlspecialchars($device['status']) ?></span>
                    </dd>
                </dl>
            </div>
        </div>
    </div>

    <!-- Merge form -->
    <div class="col-md-7">
        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h6 class="card-subtitle text-muted text-uppercase fw-semibold mb-3"
                    style="font-size:.7rem;letter-spacing:.07em">Merge Into</h6>

                <form method="POST" action="/devices/<?= (int) $device['id'] ?>/merge">

                    <div class="mb-3">
                        <label for="target_device_id" class="form-label fw-medium">
                            Target Device <span class="text-danger">*</span>
                        </label>

<?php if (empty($candidates)): ?>
                        <p class="text-muted small">
                            No other active devices available. Add another device before merging.
                        </p>
<?php else: ?>
                        <select name="target_device_id" id="target_device_id"
                                class="form-select<?= isset($errors['target_device_id']) ? ' is-invalid' : '' ?>"
                                required>
                            <option value="">— select target device —</option>
<?php foreach ($candidates as $c): ?>
                            <option value="<?= (int) $c['id'] ?>"
                                <?= (isset($_POST['target_device_id']) && (int) $_POST['target_device_id'] === (int) $c['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($c['name']) ?>
                            </option>
<?php endforeach; ?>
                        </select>
<?php if (isset($errors['target_device_id'])): ?>
                        <div class="invalid-feedback"><?= htmlspecialchars($errors['target_device_id']) ?></div>
<?php endif; ?>
<?php endif; ?>
                    </div>

                    <p class="small text-muted mb-3">
                        After the merge, you will be redirected to the target device page.
                        The source device will no longer appear in device listings.
                    </p>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-danger"
                                <?= empty($candidates) ? 'disabled' : '' ?>>
                            <i class="bi bi-arrow-left-right me-1"></i>Merge Device
                        </button>
                        <a href="/devices/<?= (int) $device['id'] ?>" class="btn btn-outline-secondary">
                            Cancel
                        </a>
                    </div>

                </form>
            </div>
        </div>
    </div>

</div>
