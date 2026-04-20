<div class="d-flex align-items-center gap-2 mb-4">
    <a href="/admin/users" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left"></i>
    </a>
    <h5 class="mb-0">Edit User</h5>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?> alert-dismissible mb-4" role="alert">
    <?= htmlspecialchars($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<?php if (isset($errors['groups'])): ?>
<div class="alert alert-danger mb-4" role="alert">
    <?= htmlspecialchars($errors['groups']) ?>
</div>
<?php endif; ?>

<!-- Sub-navigation for user edit pages -->
<div class="mb-3 d-flex gap-2">
    <span class="btn btn-sm btn-primary disabled" aria-current="page">
        <i class="bi bi-people me-1"></i>Group Membership
    </span>
    <a href="/admin/users/<?= (int) $editUser['id'] ?>/edit-account"
       class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-person me-1"></i>Account Details
    </a>
</div>

<form method="POST" action="/admin/users/<?= (int) $editUser['id'] ?>" novalidate>

<div class="row g-4">

    <!-- ── Left column: user summary (read-only) ────────���────────────── -->
    <div class="col-lg-4">

        <div class="card">
            <div class="card-header">
                <span class="fw-semibold small">Account</span>
            </div>
            <div class="card-body">

                <div class="mb-3">
                    <div class="form-label text-muted small mb-1">Display Name</div>
                    <div class="fw-semibold"><?= htmlspecialchars($editUser['display_name']) ?></div>
                </div>

                <div class="mb-3">
                    <div class="form-label text-muted small mb-1">Username</div>
                    <code><?= htmlspecialchars($editUser['username']) ?></code>
                </div>

                <div class="mb-3">
                    <div class="form-label text-muted small mb-1">Email</div>
                    <div><?= htmlspecialchars($editUser['email']) ?></div>
                </div>

                <div class="mb-3">
                    <div class="form-label text-muted small mb-1">Status</div>
                    <?php if ($editUser['is_active']): ?>
                        <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>
                    <?php else: ?>
                        <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">Inactive</span>
                    <?php endif; ?>
                </div>

                <div class="mb-0">
                    <div class="form-label text-muted small mb-1">Member since</div>
                    <div class="text-muted small"><?= htmlspecialchars(substr($editUser['created_at'], 0, 10)) ?></div>
                </div>

            </div>
            <div class="card-footer text-muted" style="font-size:.75rem;">
                Account details (name, email, password) are managed separately.
            </div>
        </div>

        <div class="mt-4">
            <button type="submit" class="btn btn-primary w-100">Save Group Membership</button>
        </div>

    </div>

    <!-- ── Right column: group checkboxes ────────────────────────────── -->
    <div class="col-lg-8">

        <div class="card">
            <div class="card-header d-flex align-items-center justify-content-between">
                <span class="fw-semibold small">Group Membership</span>
                <span class="badge bg-body-secondary text-body border js-group-count">
                    <?= count($assignedGroupIds) ?>
                </span>
            </div>

            <?php if (empty($allGroups)): ?>
            <div class="card-body text-muted small fst-italic">No groups defined.</div>
            <?php else: ?>
            <div class="card-body">
                <p class="text-muted small mb-3">
                    Select which groups this user belongs to.
                    Group membership determines which permissions the user has.
                    Changes take effect on the user's next page load.
                </p>

                <div class="row g-2">
                    <?php foreach ($allGroups as $g): ?>
                    <div class="col-sm-6">
                        <div class="p-3 border rounded h-100 <?= in_array((int) $g['id'], $assignedGroupIds, true) ? 'border-primary-subtle bg-primary-subtle' : '' ?>"
                             style="transition: background .15s, border-color .15s;">
                            <div class="form-check mb-0">
                                <input class="form-check-input js-group-check"
                                       type="checkbox"
                                       name="groups[]"
                                       value="<?= (int) $g['id'] ?>"
                                       id="group-<?= (int) $g['id'] ?>"
                                       <?= in_array((int) $g['id'], $assignedGroupIds, true) ? 'checked' : '' ?>>
                                <label class="form-check-label w-100" for="group-<?= (int) $g['id'] ?>">
                                    <span class="fw-semibold d-block"><?= htmlspecialchars($g['name']) ?></span>
                                    <?php if (!empty($g['description'])): ?>
                                    <span class="text-muted" style="font-size:.8rem;">
                                        <?= htmlspecialchars($g['description']) ?>
                                    </span>
                                    <?php endif; ?>
                                </label>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

            </div>
            <?php endif; ?>

        </div>

    </div>

</div><!-- /.row -->

</form>

<script>
// Update the group count badge and card highlight as checkboxes toggle.
(function () {
    var badge  = document.querySelector('.js-group-count');
    var checks = document.querySelectorAll('.js-group-check');

    function updateUI() {
        var n = document.querySelectorAll('.js-group-check:checked').length;
        if (badge) badge.textContent = n;

        checks.forEach(function (cb) {
            var card = cb.closest('.border');
            if (!card) return;
            if (cb.checked) {
                card.classList.add('border-primary-subtle', 'bg-primary-subtle');
            } else {
                card.classList.remove('border-primary-subtle', 'bg-primary-subtle');
            }
        });
    }

    checks.forEach(function (cb) { cb.addEventListener('change', updateUI); });
}());
</script>
