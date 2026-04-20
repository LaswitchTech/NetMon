<?php
/**
 * Create discovery job form content fragment.
 *
 * Variables available (set by DiscoveryController::jobCreateForm / jobStore before ob_start):
 *   $user        (array)              — safe user record from the principal
 *   $permissions (array)              — permission names for the authenticated user
 *   $appName     (string)             — application name from config
 *   $displayName (string)             — display_name if set, otherwise username
 *   $old         (array)              — previous form values for re-population
 *                                       keys: name, subnet, enabled
 *   $errors      (array<string,string>) — validation errors keyed by field name
 */

$oldName    = htmlspecialchars($old['name']   ?? '');
$oldSubnet  = htmlspecialchars($old['subnet'] ?? '');
$oldEnabled = !isset($old['enabled']) || $old['enabled'];  // default to enabled
?>

<!-- Breadcrumb -->
<nav aria-label="breadcrumb" class="mb-4">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="/discovery">Discovery</a></li>
        <li class="breadcrumb-item"><a href="/discovery/jobs">Jobs</a></li>
        <li class="breadcrumb-item active" aria-current="page">New Job</li>
    </ol>
</nav>

<!-- Page heading -->
<div class="mb-4">
    <h1 class="h4 fw-semibold mb-1">New Discovery Job</h1>
    <p class="text-muted mb-0 small">
        Define a subnet to scan. Run <code>scripts/discover.php</code> to execute enabled jobs.
    </p>
</div>

<div class="row">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-body p-4">
                <form method="POST" action="/discovery/jobs" novalidate>

                    <!-- Name -->
                    <div class="mb-3">
                        <label for="name" class="form-label fw-medium">Job Name</label>
                        <input type="text"
                               id="name"
                               name="name"
                               class="form-control<?= isset($errors['name']) ? ' is-invalid' : '' ?>"
                               value="<?= $oldName ?>"
                               maxlength="128"
                               required
                               placeholder="e.g. Office LAN">
                        <?php if (isset($errors['name'])): ?>
                            <div class="invalid-feedback"><?= htmlspecialchars($errors['name']) ?></div>
                        <?php endif; ?>
                    </div>

                    <!-- Subnet -->
                    <div class="mb-3">
                        <label for="subnet" class="form-label fw-medium">Subnet (CIDR)</label>
                        <input type="text"
                               id="subnet"
                               name="subnet"
                               class="form-control font-monospace<?= isset($errors['subnet']) ? ' is-invalid' : '' ?>"
                               value="<?= $oldSubnet ?>"
                               required
                               placeholder="e.g. 192.168.1.0/24">
                        <?php if (isset($errors['subnet'])): ?>
                            <div class="invalid-feedback"><?= htmlspecialchars($errors['subnet']) ?></div>
                        <?php else: ?>
                            <div class="form-text">IPv4 CIDR notation only (e.g. <code>10.0.0.0/8</code>).</div>
                        <?php endif; ?>
                    </div>

                    <!-- Enabled -->
                    <div class="mb-4">
                        <div class="form-check">
                            <input type="checkbox"
                                   id="enabled"
                                   name="enabled"
                                   class="form-check-input"
                                   value="1"
                                   <?= $oldEnabled ? 'checked' : '' ?>>
                            <label class="form-check-label" for="enabled">
                                Enabled — include in scheduled scans
                            </label>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">
                            <i class="bi bi-plus-lg me-1"></i>Create Job
                        </button>
                        <a href="/discovery/jobs" class="btn btn-outline-secondary">Cancel</a>
                    </div>

                </form>
            </div>
        </div>
    </div>
</div>
