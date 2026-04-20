<div class="d-flex align-items-center gap-2 mb-4">
    <a href="/admin/permissions" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left"></i>
    </a>
    <h5 class="mb-0">Edit Permission</h5>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?> alert-dismissible mb-4" role="alert">
    <?= htmlspecialchars($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<div class="row g-4">

    <!-- ── Left column: permission details ───────────────────────────── -->
    <div class="col-lg-6">

        <div class="card mb-4">
            <div class="card-header">
                <span class="fw-semibold small">Permission Details</span>
            </div>
            <div class="card-body">
                <form method="POST" action="/admin/permissions/<?= (int) $perm['id'] ?>" novalidate>

                    <div class="mb-3">
                        <label for="perm-name" class="form-label">
                            Code <span class="text-danger">*</span>
                        </label>
                        <input type="text"
                               id="perm-name"
                               name="name"
                               class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>"
                               value="<?= htmlspecialchars($old['name'] ?? '') ?>"
                               maxlength="128"
                               required>
                        <?php if (isset($errors['name'])): ?>
                            <div class="invalid-feedback"><?= htmlspecialchars($errors['name']) ?></div>
                        <?php else: ?>
                            <div class="form-text">
                                Lowercase dot-separated identifiers (e.g. <code>devices.manage</code>).
                                Letters, digits, and underscores within each segment.
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-4">
                        <label for="perm-desc" class="form-label">Description</label>
                        <input type="text"
                               id="perm-desc"
                               name="description"
                               class="form-control <?= isset($errors['description']) ? 'is-invalid' : '' ?>"
                               value="<?= htmlspecialchars($old['description'] ?? '') ?>"
                               maxlength="255">
                        <?php if (isset($errors['description'])): ?>
                            <div class="invalid-feedback"><?= htmlspecialchars($errors['description']) ?></div>
                        <?php endif; ?>
                    </div>

                    <button type="submit" class="btn btn-primary">Save Changes</button>

                </form>
            </div>
        </div>

        <!-- Danger zone — outside the main form to avoid nested forms -->
        <div class="card border-danger-subtle">
            <div class="card-header border-danger-subtle">
                <span class="fw-semibold small text-danger">Danger Zone</span>
            </div>
            <div class="card-body">
                <?php if ((int) $perm['group_count'] > 0): ?>
                    <p class="small text-muted mb-0">
                        This permission is currently assigned to
                        <strong><?= (int) $perm['group_count'] ?> <?= (int) $perm['group_count'] === 1 ? 'group' : 'groups' ?></strong>.
                        Remove it from all groups before deleting.
                    </p>
                <?php else: ?>
                    <p class="small text-muted mb-3">
                        Deleting this permission removes it permanently. This cannot be undone.
                    </p>
                    <form method="POST"
                          action="/admin/permissions/<?= (int) $perm['id'] ?>/delete"
                          class="js-delete-form">
                        <button type="submit" class="btn btn-outline-danger btn-sm">
                            <i class="bi bi-trash me-1"></i>Delete Permission
                        </button>
                    </form>
                <?php endif; ?>
            </div>
        </div>

    </div>

    <!-- ── Right column: assigned groups (read-only context) ─────────── -->
    <div class="col-lg-6">
        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <span class="fw-semibold small">Assigned to Groups</span>
                <span class="badge bg-body-secondary text-body border"><?= (int) $perm['group_count'] ?></span>
            </div>
            <?php if ((int) $perm['group_count'] === 0): ?>
            <div class="card-body text-muted small fst-italic">
                Not assigned to any group.
            </div>
            <?php else: ?>
            <div class="card-body text-muted small">
                This permission is held by <?= (int) $perm['group_count'] ?> <?= (int) $perm['group_count'] === 1 ? 'group' : 'groups' ?>.
                Manage group assignments from the
                <a href="/admin/groups">Groups</a> page.
            </div>
            <?php endif; ?>
        </div>
    </div>

</div>

<?php if ((int) $perm['group_count'] === 0): ?>
<script>
document.querySelector('.js-delete-form')?.addEventListener('submit', function (e) {
    if (!confirm('Delete this permission? This cannot be undone.')) {
        e.preventDefault();
    }
});
</script>
<?php endif; ?>
