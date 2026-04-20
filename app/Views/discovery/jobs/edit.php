<?php
/**
 * Edit discovery job form content fragment.
 *
 * Variables available (set by DiscoveryController::jobEditForm / jobUpdate before ob_start):
 *   $user        (array)              — safe user record from the principal
 *   $permissions (array)              — permission names for the authenticated user
 *   $appName     (string)             — application name from config
 *   $displayName (string)             — display_name if set, otherwise username
 *   $job         (array)              — existing job row (id, name, subnet, enabled, ...)
 *   $old         (array)              — form values for re-population (name, subnet, enabled)
 *   $errors      (array<string,string>) — validation errors keyed by field name
 */

$jobId      = (int) $job['id'];
$oldName    = htmlspecialchars($old['name']   ?? $job['name']);
$oldSubnet  = htmlspecialchars($old['subnet'] ?? $job['subnet']);
$oldEnabled = $old['enabled'] ?? (bool) $job['enabled'];
?>

<!-- Breadcrumb -->
<nav aria-label="breadcrumb" class="mb-4">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="/discovery">Discovery</a></li>
        <li class="breadcrumb-item"><a href="/discovery/jobs">Jobs</a></li>
        <li class="breadcrumb-item active" aria-current="page">
            <?= htmlspecialchars($job['name']) ?>
        </li>
    </ol>
</nav>

<!-- Page heading -->
<div class="mb-4">
    <h1 class="h4 fw-semibold mb-1">Edit Job</h1>
    <p class="text-muted mb-0 small">
        Update the scan configuration for this discovery job.
    </p>
</div>

<div class="row">
    <div class="col-lg-6">
        <div class="card">
            <div class="card-body p-4">
                <form method="POST" action="/discovery/jobs/<?= $jobId ?>" novalidate>

                    <!-- Name -->
                    <div class="mb-3">
                        <label for="name" class="form-label fw-medium">Job Name</label>
                        <input type="text"
                               id="name"
                               name="name"
                               class="form-control<?= isset($errors['name']) ? ' is-invalid' : '' ?>"
                               value="<?= $oldName ?>"
                               maxlength="128"
                               required>
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
                               required>
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
                            <i class="bi bi-check-lg me-1"></i>Save Changes
                        </button>
                        <a href="/discovery/jobs" class="btn btn-outline-secondary">Cancel</a>
                    </div>

                </form>
            </div>
        </div>

        <!-- Danger zone -->
        <div class="card mt-4 border-danger">
            <div class="card-header bg-transparent text-danger border-danger">
                <h2 class="h6 fw-semibold mb-0">Danger Zone</h2>
            </div>
            <div class="card-body">
                <p class="small mb-3">
                    Deleting this job permanently removes it along with all its findings.
                    This cannot be undone.
                </p>
                <button type="button"
                        class="btn btn-sm btn-outline-danger"
                        data-bs-toggle="modal"
                        data-bs-target="#modal-delete">
                    <i class="bi bi-trash me-1"></i>Delete This Job
                </button>
            </div>
        </div>
    </div>
</div>

<!-- Delete confirmation modal -->
<div class="modal fade" id="modal-delete" tabindex="-1"
     aria-labelledby="modal-delete-label" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modal-delete-label">Delete Job</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>
                    Delete job <strong><?= htmlspecialchars($job['name']) ?></strong>
                    (<code><?= htmlspecialchars($job['subnet']) ?></code>)?
                </p>
                <div class="alert alert-warning d-flex gap-2 align-items-start mb-0">
                    <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1"></i>
                    <span>
                        All findings associated with this job will be permanently deleted.
                        This cannot be undone.
                    </span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" action="/discovery/jobs/<?= $jobId ?>/delete">
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-trash me-1"></i>Delete Job
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
