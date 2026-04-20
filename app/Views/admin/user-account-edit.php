<div class="d-flex align-items-center gap-2 mb-4">
    <a href="/admin/users" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left"></i>
    </a>
    <h5 class="mb-0">Edit Account — <?= htmlspecialchars($editUser['display_name']) ?></h5>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?> alert-dismissible mb-4" role="alert">
    <?= htmlspecialchars($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<div class="row g-4 justify-content-center">
<div class="col-lg-7">

<!-- Sub-navigation for user edit pages -->
<div class="mb-3 d-flex gap-2">
    <a href="/admin/users/<?= (int) $editUser['id'] ?>/edit"
       class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-people me-1"></i>Group Membership
    </a>
    <span class="btn btn-sm btn-primary disabled" aria-current="page">
        <i class="bi bi-person me-1"></i>Account Details
    </span>
</div>

<form method="POST" action="/admin/users/<?= (int) $editUser['id'] ?>/account" novalidate>

    <div class="card mb-4">
        <div class="card-header">
            <span class="fw-semibold small">Account Details</span>
        </div>
        <div class="card-body">

            <!-- Read-only username badge -->
            <div class="mb-3">
                <div class="form-label text-muted small mb-1">Username</div>
                <code class="text-muted"><?= htmlspecialchars($editUser['username']) ?></code>
                <div class="form-text">Username cannot be changed after account creation.</div>
            </div>

            <!-- Display Name -->
            <div class="mb-3">
                <label for="display_name" class="form-label">
                    Display Name <span class="text-danger">*</span>
                </label>
                <input type="text"
                       class="form-control<?= isset($errors['display_name']) ? ' is-invalid' : '' ?>"
                       id="display_name"
                       name="display_name"
                       value="<?= htmlspecialchars($old['display_name'] ?? '') ?>"
                       maxlength="100"
                       required>
                <?php if (isset($errors['display_name'])): ?>
                <div class="invalid-feedback"><?= htmlspecialchars($errors['display_name']) ?></div>
                <?php endif; ?>
            </div>

            <!-- Email -->
            <div class="mb-0">
                <label for="email" class="form-label">
                    Email Address <span class="text-danger">*</span>
                </label>
                <input type="email"
                       class="form-control<?= isset($errors['email']) ? ' is-invalid' : '' ?>"
                       id="email"
                       name="email"
                       value="<?= htmlspecialchars($old['email'] ?? '') ?>"
                       autocomplete="off"
                       required>
                <?php if (isset($errors['email'])): ?>
                <div class="invalid-feedback"><?= htmlspecialchars($errors['email']) ?></div>
                <?php endif; ?>
            </div>

        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">
            <span class="fw-semibold small">Reset Password</span>
        </div>
        <div class="card-body">
            <p class="text-muted small mb-3">Leave both fields blank to keep the current password.</p>

            <!-- New Password -->
            <div class="mb-3">
                <label for="password" class="form-label">New Password</label>
                <input type="password"
                       class="form-control<?= isset($errors['password']) ? ' is-invalid' : '' ?>"
                       id="password"
                       name="password"
                       autocomplete="new-password">
                <?php if (isset($errors['password'])): ?>
                <div class="invalid-feedback"><?= htmlspecialchars($errors['password']) ?></div>
                <?php else: ?>
                <div class="form-text">Minimum 8 characters.</div>
                <?php endif; ?>
            </div>

            <!-- Confirm Password -->
            <div class="mb-0">
                <label for="password_confirm" class="form-label">Confirm New Password</label>
                <input type="password"
                       class="form-control<?= isset($errors['password_confirm']) ? ' is-invalid' : '' ?>"
                       id="password_confirm"
                       name="password_confirm"
                       autocomplete="new-password">
                <?php if (isset($errors['password_confirm'])): ?>
                <div class="invalid-feedback"><?= htmlspecialchars($errors['password_confirm']) ?></div>
                <?php endif; ?>
            </div>

        </div>
        <div class="card-footer d-flex justify-content-end gap-2">
            <a href="/admin/users" class="btn btn-outline-secondary btn-sm">Cancel</a>
            <button type="submit" class="btn btn-primary btn-sm">Save Changes</button>
        </div>
    </div>

</form>

</div>
</div>
