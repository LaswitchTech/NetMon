<div class="d-flex align-items-center gap-2 mb-4">
    <a href="/admin/users" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-arrow-left"></i>
    </a>
    <h5 class="mb-0">Create User</h5>
</div>

<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?> alert-dismissible mb-4" role="alert">
    <?= htmlspecialchars($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<form method="POST" action="/admin/users" novalidate>

<div class="row g-4 justify-content-center">
    <div class="col-lg-7">

        <div class="card">
            <div class="card-header">
                <span class="fw-semibold small">Account Details</span>
            </div>
            <div class="card-body">

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

                <!-- Username -->
                <div class="mb-3">
                    <label for="username" class="form-label">
                        Username <span class="text-danger">*</span>
                    </label>
                    <input type="text"
                           class="form-control<?= isset($errors['username']) ? ' is-invalid' : '' ?>"
                           id="username"
                           name="username"
                           value="<?= htmlspecialchars($old['username'] ?? '') ?>"
                           maxlength="64"
                           autocomplete="off"
                           required>
                    <?php if (isset($errors['username'])): ?>
                    <div class="invalid-feedback"><?= htmlspecialchars($errors['username']) ?></div>
                    <?php else: ?>
                    <div class="form-text">Letters, digits, dots, underscores, and hyphens only.</div>
                    <?php endif; ?>
                </div>

                <!-- Email -->
                <div class="mb-3">
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

                <hr class="my-4">

                <!-- Password -->
                <div class="mb-3">
                    <label for="password" class="form-label">
                        Password <span class="text-danger">*</span>
                    </label>
                    <input type="password"
                           class="form-control<?= isset($errors['password']) ? ' is-invalid' : '' ?>"
                           id="password"
                           name="password"
                           autocomplete="new-password"
                           required>
                    <?php if (isset($errors['password'])): ?>
                    <div class="invalid-feedback"><?= htmlspecialchars($errors['password']) ?></div>
                    <?php else: ?>
                    <div class="form-text">Minimum 8 characters.</div>
                    <?php endif; ?>
                </div>

                <!-- Confirm Password -->
                <div class="mb-0">
                    <label for="password_confirm" class="form-label">
                        Confirm Password <span class="text-danger">*</span>
                    </label>
                    <input type="password"
                           class="form-control<?= isset($errors['password_confirm']) ? ' is-invalid' : '' ?>"
                           id="password_confirm"
                           name="password_confirm"
                           autocomplete="new-password"
                           required>
                    <?php if (isset($errors['password_confirm'])): ?>
                    <div class="invalid-feedback"><?= htmlspecialchars($errors['password_confirm']) ?></div>
                    <?php endif; ?>
                </div>

            </div>
            <div class="card-footer d-flex justify-content-end gap-2">
                <a href="/admin/users" class="btn btn-outline-secondary btn-sm">Cancel</a>
                <button type="submit" class="btn btn-primary btn-sm">Create User</button>
            </div>
        </div>

    </div>
</div>

</form>
