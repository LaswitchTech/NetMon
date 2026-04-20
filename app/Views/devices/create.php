<?php
/**
 * Add Device — content fragment.
 *
 * Variables available (set by DeviceController::createForm / store before ob_start):
 *   $errors      (array)         — validation errors keyed by field name
 *   $old         (array)         — previously submitted values for re-population on error
 *   $user        (array)
 *   $permissions (array)
 *   $appName     (string)
 *   $displayName (string)
 */
?>

<!-- Breadcrumb + heading -->
<div class="mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb small mb-2">
            <li class="breadcrumb-item"><a href="/devices">Devices</a></li>
            <li class="breadcrumb-item active" aria-current="page">Add Device</li>
        </ol>
    </nav>
    <h1 class="h4 fw-semibold mb-1">Add Device</h1>
    <p class="text-muted mb-0 small">Register a new network device for monitoring.</p>
</div>

<div class="card" style="max-width: 540px;">
    <div class="card-body p-4">
        <form method="post" action="/devices" novalidate>

            <!-- Name -->
            <div class="mb-3">
                <label for="name" class="form-label fw-medium">
                    Device Name <span class="text-danger">*</span>
                </label>
                <input type="text"
                       class="form-control<?= isset($errors['name']) ? ' is-invalid' : '' ?>"
                       id="name" name="name" maxlength="128"
                       value="<?= htmlspecialchars($old['name'] ?? '') ?>"
                       placeholder="e.g. Core Router"
                       autofocus required>
                <?php if (isset($errors['name'])): ?>
                    <div class="invalid-feedback"><?= htmlspecialchars($errors['name']) ?></div>
                <?php endif; ?>
            </div>

            <!-- Host / IP address -->
            <div class="mb-3">
                <label for="address" class="form-label fw-medium">
                    Host / IP Address <span class="text-danger">*</span>
                </label>
                <input type="text"
                       class="form-control<?= isset($errors['address']) ? ' is-invalid' : '' ?>"
                       id="address" name="address"
                       value="<?= htmlspecialchars($old['address'] ?? '') ?>"
                       placeholder="e.g. 192.168.1.1 or router.example.com"
                       required>
                <?php if (isset($errors['address'])): ?>
                    <div class="invalid-feedback"><?= htmlspecialchars($errors['address']) ?></div>
                <?php else: ?>
                    <div class="form-text">Accepts IPv4, IPv6, or a hostname.</div>
                <?php endif; ?>
            </div>

            <!-- Description (optional) -->
            <div class="mb-4">
                <label for="description" class="form-label fw-medium">Description</label>
                <input type="text"
                       class="form-control"
                       id="description" name="description" maxlength="255"
                       value="<?= htmlspecialchars($old['description'] ?? '') ?>"
                       placeholder="Optional notes about this device">
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-plus-lg me-1"></i>Add Device
                </button>
                <a href="/devices" class="btn btn-outline-secondary">Cancel</a>
            </div>

        </form>
    </div>
</div>
