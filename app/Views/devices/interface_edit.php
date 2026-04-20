<?php
/**
 * Edit Interface — content fragment.
 *
 * Variables:
 *   $iface       (array)  — interface row (mutated with submitted values on 422)
 *   $device      (array)  — parent device row
 *   $errors      (array)  — validation errors keyed by field name
 *   $user, $permissions, $appName, $displayName
 */
?>

<div class="mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb small mb-2">
            <li class="breadcrumb-item"><a href="/devices">Devices</a></li>
            <li class="breadcrumb-item"><a href="/devices/<?= (int) $device['id'] ?>"><?= htmlspecialchars($device['name']) ?></a></li>
            <li class="breadcrumb-item active" aria-current="page">Edit Interface</li>
        </ol>
    </nav>
    <h1 class="h4 fw-semibold mb-1">Edit Interface</h1>
    <p class="text-muted mb-0 small">Update interface details for <?= htmlspecialchars($device['name']) ?>.</p>
</div>

<div class="card" style="max-width: 540px;">
    <div class="card-body p-4">
        <form method="post" action="/devices/interfaces/<?= (int) $iface['id'] ?>" novalidate>

            <!-- Name -->
            <div class="mb-3">
                <label for="name" class="form-label fw-medium">
                    Interface Name <span class="text-danger">*</span>
                </label>
                <input type="text"
                       class="form-control<?= isset($errors['name']) ? ' is-invalid' : '' ?>"
                       id="name" name="name" maxlength="64"
                       value="<?= htmlspecialchars($iface['name']) ?>"
                       autofocus required>
                <?php if (isset($errors['name'])): ?>
                    <div class="invalid-feedback"><?= htmlspecialchars($errors['name']) ?></div>
                <?php endif; ?>
            </div>

            <!-- MAC Address -->
            <div class="mb-3">
                <label for="mac_address" class="form-label fw-medium">MAC Address</label>
                <input type="text"
                       class="form-control<?= isset($errors['mac_address']) ? ' is-invalid' : '' ?>"
                       id="mac_address" name="mac_address"
                       value="<?= htmlspecialchars($iface['mac_address'] ?? '') ?>"
                       placeholder="e.g. 00:11:22:33:44:55">
                <?php if (isset($errors['mac_address'])): ?>
                    <div class="invalid-feedback"><?= htmlspecialchars($errors['mac_address']) ?></div>
                <?php else: ?>
                    <div class="form-text">Optional. Used for device identity matching.</div>
                <?php endif; ?>
            </div>

            <!-- Management interface -->
            <div class="mb-3">
                <div class="form-check">
                    <input class="form-check-input<?= isset($errors['is_management']) ? ' is-invalid' : '' ?>"
                           type="checkbox" id="is_management" name="is_management" value="1"
                           <?= $iface['is_management'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="is_management">
                        Management interface
                    </label>
                    <?php if (isset($errors['is_management'])): ?>
                        <div class="invalid-feedback"><?= htmlspecialchars($errors['is_management']) ?></div>
                    <?php else: ?>
                        <div class="form-text">The management interface is used for reachability monitoring and as the primary contact address.</div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Description -->
            <div class="mb-4">
                <label for="description" class="form-label fw-medium">Description</label>
                <input type="text"
                       class="form-control"
                       id="description" name="description" maxlength="255"
                       value="<?= htmlspecialchars($iface['description'] ?? '') ?>"
                       placeholder="Optional notes about this interface">
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-check-lg me-1"></i>Save Changes
                </button>
                <a href="/devices/<?= (int) $device['id'] ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>

        </form>
    </div>
</div>
