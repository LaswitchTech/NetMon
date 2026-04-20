<?php
/**
 * Edit Monitored Service — content fragment.
 *
 * Variables:
 *   $service    (array)   — service row (mutated with submitted values on 422)
 *   $device     (array)   — parent device row
 *   $protocols  (array)   — allowed protocol values (e.g. ['tcp'])
 *   $errors     (array)   — validation errors keyed by field name
 *   $user, $permissions, $appName, $displayName
 *
 * Note: $service['last_state'] and $service['last_check_at'] are displayed
 * read-only and are never included in the form submission.
 */
?>

<div class="mb-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb small mb-2">
            <li class="breadcrumb-item"><a href="/devices">Devices</a></li>
            <li class="breadcrumb-item"><a href="/devices/<?= (int) $device['id'] ?>"><?= htmlspecialchars($device['name']) ?></a></li>
            <li class="breadcrumb-item active" aria-current="page">Edit Monitored Service</li>
        </ol>
    </nav>
    <h1 class="h4 fw-semibold mb-1">Edit Monitored Service</h1>
    <p class="text-muted mb-0 small">Update service configuration on <?= htmlspecialchars($device['name']) ?>.</p>
</div>

<div class="card" style="max-width: 540px;">
    <div class="card-body p-4">

        <?php if ($service['last_state'] !== null): ?>
        <!-- Read-only current state (monitoring-managed, not a form field) -->
        <div class="mb-4 p-3 rounded" style="background:var(--app-panel-2); border:1px solid var(--app-border);">
            <div class="small text-muted mb-1">Current monitoring state</div>
            <?php
                $stateBadge = match ($service['last_state']) {
                    'up'    => 'bg-success',
                    'down'  => 'bg-danger',
                    'error' => 'bg-secondary',
                    default => 'bg-secondary',
                };
            ?>
            <span class="badge <?= $stateBadge ?> me-2"><?= htmlspecialchars($service['last_state']) ?></span>
            <span class="small text-muted">
                Last checked: <?= htmlspecialchars($service['last_check_at'] ?? 'Never') ?>
            </span>
        </div>
        <?php endif; ?>

        <form method="post" action="/devices/services/<?= (int) $service['id'] ?>" novalidate>

            <!-- Service name -->
            <div class="mb-3">
                <label for="name" class="form-label fw-medium">
                    Service Name <span class="text-danger">*</span>
                </label>
                <input type="text"
                       class="form-control<?= isset($errors['name']) ? ' is-invalid' : '' ?>"
                       id="name" name="name" maxlength="128"
                       value="<?= htmlspecialchars($service['name']) ?>"
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
                        <?= $service['protocol'] === $p ? 'selected' : '' ?>>
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
                       value="<?= htmlspecialchars((string) $service['port']) ?>"
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
                           <?= $service['monitoring_enabled'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="monitoring_enabled">
                        Monitoring enabled
                    </label>
                    <div class="form-text">Uncheck to pause monitoring for this service without deleting it.</div>
                </div>
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
