<?php
/**
 * "Create device from finding" content fragment.
 *
 * Variables available (set by DiscoveryController before ob_start):
 *   $finding     (array)   — source finding row (with job_name, job_subnet)
 *   $errors      (array)   — validation errors keyed by field name
 *   $old         (array)   — previously submitted / pre-filled values
 *   $user, $permissions, $appName, $displayName
 */

$findingId = (int) $finding['id'];
?>

<!-- Breadcrumb -->
<div class="mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb small mb-2">
            <li class="breadcrumb-item"><a href="/discovery">Discovery</a></li>
            <li class="breadcrumb-item">
                <a href="/discovery/<?= $findingId ?>">
                    <?= htmlspecialchars($finding['ip_address']) ?>
                </a>
            </li>
            <li class="breadcrumb-item active" aria-current="page">Create Device</li>
        </ol>
    </nav>
    <h1 class="h4 fw-semibold mb-1">Create Device from Finding</h1>
    <p class="text-muted mb-0 small">
        Register
        <span class="font-monospace"><?= htmlspecialchars($finding['ip_address']) ?></span>
        as a new monitored device. The IP address will be assigned to the device's
        management interface automatically.
    </p>
</div>

<!-- Context notice -->
<div class="alert alert-info small mb-4 d-flex gap-2 align-items-start" role="alert">
    <i class="bi bi-info-circle-fill flex-shrink-0 mt-1"></i>
    <div>
        Found by job <strong><?= htmlspecialchars($finding['job_name']) ?></strong>
        scanning <span class="font-monospace"><?= htmlspecialchars($finding['job_subnet']) ?></span>.
        The address field is pre-filled with the discovered IP — edit it if needed.
    </div>
</div>

<div class="card" style="max-width: 540px;">
    <div class="card-body p-4">
        <form method="post" action="/discovery/<?= $findingId ?>/create-device" novalidate>

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

            <!-- Host / IP address (pre-filled from finding) -->
            <div class="mb-3">
                <label for="address" class="form-label fw-medium">
                    Host / IP Address <span class="text-danger">*</span>
                </label>
                <input type="text"
                       class="form-control<?= isset($errors['address']) ? ' is-invalid' : '' ?>"
                       id="address" name="address"
                       value="<?= htmlspecialchars($old['address'] ?? $finding['ip_address']) ?>"
                       placeholder="e.g. 192.168.1.1"
                       required>
                <?php if (isset($errors['address'])): ?>
                    <div class="invalid-feedback"><?= htmlspecialchars($errors['address']) ?></div>
                <?php else: ?>
                    <div class="form-text">Pre-filled from the discovered IP. Accepts IPv4, IPv6, or a hostname.</div>
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
                    <i class="bi bi-plus-lg me-1"></i>Create Device
                </button>
                <a href="/discovery/<?= $findingId ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>

        </form>
    </div>
</div>
