<?php
/**
 * Add Monitored Service — content fragment.
 *
 * Variables:
 *   $device     (array)   — parent device row
 *   $protocols  (array)   — allowed protocol values (e.g. ['tcp'])
 *   $errors     (array)   — validation errors keyed by field name
 *   $old        (array)   — previously submitted values for re-population on error
 *   $user, $permissions, $appName, $displayName
 */
?>

<div class="mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb small mb-2">
            <li class="breadcrumb-item"><a href="/devices">Devices</a></li>
            <li class="breadcrumb-item"><a href="/devices/<?= (int) $device['id'] ?>"><?= htmlspecialchars($device['name']) ?></a></li>
            <li class="breadcrumb-item active" aria-current="page">Add Monitored Service</li>
        </ol>
    </nav>
    <h1 class="h4 fw-semibold mb-1">Add Monitored Service</h1>
    <p class="text-muted mb-0 small">Configure a TCP port to monitor on <?= htmlspecialchars($device['name']) ?>.</p>
</div>

<div class="card" style="max-width: 540px;">
    <div class="card-body p-4">
        <form method="post" action="/devices/<?= (int) $device['id'] ?>/services" novalidate>

            <!-- Service name -->
            <div class="mb-3">
                <label for="name" class="form-label fw-medium">
                    Service Name <span class="text-danger">*</span>
                </label>
                <input type="text"
                       class="form-control<?= isset($errors['name']) ? ' is-invalid' : '' ?>"
                       id="name" name="name" maxlength="128"
                       value="<?= htmlspecialchars($old['name'] ?? '') ?>"
                       placeholder="e.g. SSH, HTTPS, SMB"
                       autofocus required>
                <?php if (isset($errors['name'])): ?>
                    <div class="invalid-feedback"><?= htmlspecialchars($errors['name']) ?></div>
                <?php endif; ?>
            </div>

            <!-- Protocol -->
            <div class="mb-3">
                <label for="protocol" class="form-label fw-medium">
                    Protocol <span class="text-danger">*</span>
                </label>
                <select class="form-select<?= isset($errors['protocol']) ? ' is-invalid' : '' ?>"
                        id="protocol" name="protocol" required>
                    <?php foreach ($protocols as $p): ?>
                    <option value="<?= htmlspecialchars($p) ?>"
                        <?= ($old['protocol'] ?? 'tcp') === $p ? 'selected' : '' ?>>
                        <?= strtoupper(htmlspecialchars($p)) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php if (isset($errors['protocol'])): ?>
                    <div class="invalid-feedback"><?= htmlspecialchars($errors['protocol']) ?></div>
                <?php else: ?>
                    <div class="form-text">TCP is the only supported protocol in this release.</div>
                <?php endif; ?>
            </div>

            <!-- Port -->
            <div class="mb-3">
                <label for="port" class="form-label fw-medium">
                    Port <span class="text-danger">*</span>
                </label>
                <input type="number"
                       class="form-control<?= isset($errors['port']) ? ' is-invalid' : '' ?>"
                       id="port" name="port" min="1" max="65535"
                       value="<?= htmlspecialchars($old['port'] ?? '') ?>"
                       placeholder="e.g. 22, 443, 80"
                       required>
                <?php if (isset($errors['port'])): ?>
                    <div class="invalid-feedback"><?= htmlspecialchars($errors['port']) ?></div>
                <?php else: ?>
                    <div class="form-text">TCP port number (1–65535).</div>
                <?php endif; ?>
            </div>

            <!-- Monitoring enabled -->
            <div class="mb-4">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox"
                           id="monitoring_enabled" name="monitoring_enabled" value="1"
                           <?= !isset($old['monitoringEnabled']) || $old['monitoringEnabled'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="monitoring_enabled">
                        Monitoring enabled
                    </label>
                    <div class="form-text">Uncheck to add the service without activating monitoring yet.</div>
                </div>
            </div>

            <div class="d-flex gap-2">
                <button type="submit" class="btn btn-primary">
                    <i class="bi bi-plus-lg me-1"></i>Add Service
                </button>
                <a href="/devices/<?= (int) $device['id'] ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>

        </form>
    </div>
</div>
