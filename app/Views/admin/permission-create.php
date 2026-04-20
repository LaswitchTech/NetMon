<div class="row justify-content-center">
    <div class="col-lg-6">

        <div class="d-flex align-items-center gap-2 mb-4">
            <a href="/admin/permissions" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left"></i>
            </a>
            <h5 class="mb-0">New Permission</h5>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="POST" action="/admin/permissions" novalidate>

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
                               required
                               autofocus
                               placeholder="e.g. devices.manage">
                        <?php if (isset($errors['name'])): ?>
                            <div class="invalid-feedback"><?= htmlspecialchars($errors['name']) ?></div>
                        <?php else: ?>
                            <div class="form-text">
                                Lowercase dot-separated identifiers (e.g. <code>devices.manage</code>).
                                Letters, digits, and underscores within each segment. Must be unique.
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
                               maxlength="255"
                               placeholder="Human-readable description (optional)">
                        <?php if (isset($errors['description'])): ?>
                            <div class="invalid-feedback"><?= htmlspecialchars($errors['description']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Create Permission</button>
                        <a href="/admin/permissions" class="btn btn-outline-secondary">Cancel</a>
                    </div>

                </form>
            </div>
        </div>

    </div>
</div>
