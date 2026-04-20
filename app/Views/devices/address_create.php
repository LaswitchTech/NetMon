<?php
/**
 * Add Address — content fragment.
 *
 * Variables:
 *   $iface       (array)  — parent interface row
 *   $device      (array)  — parent device row
 *   $errors      (array)  — validation errors keyed by field name
 *   $old         (array)  — previously submitted values for re-population on error
 *   $user, $permissions, $appName, $displayName
 */
?>

<div class="mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb small mb-2">
            <li class="breadcrumb-item"><a href="/devices">Devices</a></li>
            <li class="breadcrumb-item"><a href="/devices/<?= (int) $device['id'] ?>"><?= htmlspecialchars($device['name']) ?></a></li>
            <li class="breadcrumb-item active" aria-current="page">Add Address</li>
        </ol>
    </nav>
    <h1 class="h4 fw-semibold mb-1">Add Address</h1>
    <p class="text-muted mb-0 small">
        Add an address to interface <strong><?= htmlspecialchars($iface['name']) ?></strong>
        on <?= htmlspecialchars($device['name']) ?>.
    </p>
</div>

<div class="card" style="max-width: 540px;">
    <div class="card-body p-4">
        <form method="post" action="/devices/interfaces/<?= (int) $iface['id'] ?>/addresses" novalidate>

            <!-- Address -->
            <div class="mb-3">
                <label for="address" class="form-label fw-medium">
                    IP Address / Hostname <span class="text-danger">*</span>
                </label>
                <input type="text"
                       class="form-control<?= isset($errors['address']) ? ' is-invalid' : '' ?>"
                       id="address" name="address"
                       value="<?= htmlspecialchars($old['address'] ?? '') ?>"
                       placeholder="e.g. 192.168.1.10 or fe80::1"
                       autofocus required>
                <?php if (isset($errors['address'])): ?>
                    <div class="invalid-feedback"><?= htmlspecialchars($errors['address']) ?></div>
                <?php else: ?>
                    <div class="form-text">Accepts IPv4, IPv6, or a hostname. Family is detected automatically.</div>
                <?php endif; ?>
            </div>

            <!-- Primary -->
            <div class="mb-4">
                <div class="form-check">
                    <input class="form-check-input"
                           type="checkbox" id="is_primary" name="is_primary" value="1"
                           <?= !empty($old['isPrimary']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="is_primary">
                        Primary address
                    </label>
                    <div class="form-text">Mark as the primary address for this interface. Any existing primary will be demoted.</div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-plus-lg me-1"></i>Add Address
                </button>
                <a href="/devices/<?= (int) $device['id'] ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>

        </form>
    </div>
</div>
