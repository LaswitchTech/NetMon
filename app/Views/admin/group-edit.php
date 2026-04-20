<div class="d-flex align-items-center gap-2 mb-4">
    <a href="/admin/groups" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left"></i>
    </a>
    <h5 class="mb-0">
        Edit Group
        <?php if ($isSystem): ?>
            <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle ms-1 align-middle"
                  style="font-size:.7rem;">system</span>
        <?php endif; ?>
    </h5>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?> alert-dismissible mb-4" role="alert">
    <?= htmlspecialchars($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<?php if (isset($errors['permissions'])): ?>
<div class="alert alert-danger mb-4" role="alert">
    <?= htmlspecialchars($errors['permissions']) ?>
</div>
<?php endif; ?>

<!-- Single form wraps both columns so name/description and permissions save together -->
<form method="POST" action="/admin/groups/<?= (int) $group['id'] ?>" novalidate>

<div class="row g-4">

    <!-- ── Left column: group details ──────────────────────────────── -->
    <div class="col-lg-5">

        <div class="card mb-4">
            <div class="card-header">
                <span class="fw-semibold small">Group Details</span>
            </div>
            <div class="card-body">

                <div class="mb-3">
                    <label for="group-name" class="form-label">
                        Name <span class="text-danger">*</span>
                    </label>
                    <?php if ($isSystem): ?>
                        <input type="text"
                               id="group-name"
                               class="form-control"
                               value="<?= htmlspecialchars($group['name']) ?>"
                               disabled>
                        <input type="hidden" name="name" value="<?= htmlspecialchars($group['name']) ?>">
                        <div class="form-text text-muted">System group names cannot be changed.</div>
                    <?php else: ?>
                        <input type="text"
                               id="group-name"
                               name="name"
                               class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>"
                               value="<?= htmlspecialchars($old['name'] ?? $group['name']) ?>"
                               maxlength="64"
                               required>
                        <?php if (isset($errors['name'])): ?>
                            <div class="invalid-feedback"><?= htmlspecialchars($errors['name']) ?></div>
                        <?php else: ?>
                            <div class="form-text">Letters, numbers, dots, dashes, and underscores.</div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>

                <div class="mb-4">
                    <label for="group-desc" class="form-label">Description</label>
                    <input type="text"
                           id="group-desc"
                           name="description"
                           class="form-control <?= isset($errors['description']) ? 'is-invalid' : '' ?>"
                           value="<?= htmlspecialchars($old['description'] ?? $group['description'] ?? '') ?>"
                           maxlength="255">
                    <?php if (isset($errors['description'])): ?>
                        <div class="invalid-feedback"><?= htmlspecialchars($errors['description']) ?></div>
                    <?php endif; ?>
                </div>

                <button type="submit" class="btn btn-primary">Save Changes</button>

            </div>
        </div>

        <!-- Members (read-only — displayed here for context, not part of this form) -->
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <span class="fw-semibold small">Members</span>
                <span class="badge bg-body-secondary text-body border"><?= count($members) ?></span>
            </div>
            <?php if (empty($members)): ?>
            <div class="card-body text-muted small fst-italic">No members.</div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Display Name</th>
                            <th>Username</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($members as $m): ?>
                        <tr>
                            <td><?= htmlspecialchars($m['display_name']) ?></td>
                            <td><code class="text-muted"><?= htmlspecialchars($m['username']) ?></code></td>
                            <td>
                                <?php if ($m['is_active']): ?>
                                    <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">Inactive</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

    </div>

    <!-- ── Right column: permissions ────────────────────────────────── -->
    <div class="col-lg-7">

        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <span class="fw-semibold small">Permissions</span>
                <span class="badge bg-body-secondary text-body border js-perm-count">
                    <?= count($assignedPermIds) ?>
                </span>
            </div>

            <?php if (empty($allPermissions)): ?>
            <div class="card-body text-muted small fst-italic">No permissions defined.</div>
            <?php else: ?>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Select which permissions this group grants to its members.
                    Changes are saved when you click <strong>Save Changes</strong>.
                </p>

                <?php
                // Group permissions by prefix for readability.
                // Permissions without a dot go into 'General'.
                $grouped = [];
                foreach ($allPermissions as $p) {
                    $dot = strpos($p['name'], '.');
                    $prefix = $dot !== false ? substr($p['name'], 0, $dot) : 'general';
                    $grouped[$prefix][] = $p;
                }
                ksort($grouped);
                ?>

                <?php foreach ($grouped as $prefix => $perms): ?>
                <div class="mb-3">
                    <div class="text-muted small fw-semibold text-uppercase mb-2"
                         style="font-size:.7rem; letter-spacing:.05em;">
                        <?= htmlspecialchars(ucfirst($prefix)) ?>
                    </div>
                    <?php foreach ($perms as $p): ?>
                    <div class="form-check mb-2">
                        <input class="form-check-input js-perm-check"
                               type="checkbox"
                               name="permissions[]"
                               value="<?= (int) $p['id'] ?>"
                               id="perm-<?= (int) $p['id'] ?>"
                               <?= in_array((int) $p['id'], $assignedPermIds, true) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="perm-<?= (int) $p['id'] ?>">
                            <code class="me-1"><?= htmlspecialchars($p['name']) ?></code>
                            <?php if (!empty($p['description'])): ?>
                            <span class="text-muted" style="font-size:.8rem;">
                                &mdash; <?= htmlspecialchars($p['description']) ?>
                            </span>
                            <?php endif; ?>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endforeach; ?>

            </div>
            <?php endif; ?>

        </div>

    </div>

</div><!-- /.row -->

</form>

<?php if (!$isSystem): ?>
<!-- Danger zone is outside the main form to avoid nested forms -->
<div class="row mt-4">
    <div class="col-lg-5">
        <div class="card border-danger-subtle">
            <div class="card-header border-danger-subtle">
                <span class="fw-semibold small text-danger">Danger Zone</span>
            </div>
            <div class="card-body">
                <p class="small text-muted mb-3">
                    Deleting this group removes all member assignments and permission grants.
                    This cannot be undone.
                </p>
                <form method="POST"
                      action="/admin/groups/<?= (int) $group['id'] ?>/delete"
                      class="js-delete-form">
                    <button type="submit" class="btn btn-outline-danger btn-sm">
                        <i class="bi bi-trash me-1"></i>Delete Group
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
// Update the permission count badge as checkboxes are toggled.
(function () {
    var badge    = document.querySelector('.js-perm-count');
    var checks   = document.querySelectorAll('.js-perm-check');

    function updateCount() {
        var n = document.querySelectorAll('.js-perm-check:checked').length;
        if (badge) badge.textContent = n;
    }

    checks.forEach(function (cb) { cb.addEventListener('change', updateCount); });
}());

// Confirm before delete.
document.querySelector('.js-delete-form')?.addEventListener('submit', function (e) {
    if (!confirm('Delete this group? All memberships and permission grants will be removed. This cannot be undone.')) {
        e.preventDefault();
    }
});
</script>
