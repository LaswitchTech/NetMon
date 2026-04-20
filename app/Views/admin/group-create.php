<div class="row justify-content-center">
    <div class="col-lg-6">

        <div class="d-flex align-items-center gap-2 mb-4">
            <a href="/admin/groups" class="btn btn-sm btn-outline-secondary">
                <i class="bi bi-arrow-left"></i>
            </a>
            <h5 class="mb-0">New Group</h5>
        </div>

        <div class="card">
            <div class="card-body">
                <form method="POST" action="/admin/groups" novalidate>

                    <div class="mb-3">
                        <label for="group-name" class="form-label">
                            Name <span class="text-danger">*</span>
                        </label>
                        <input type="text"
                               id="group-name"
                               name="name"
                               class="form-control <?= isset($errors['name']) ? 'is-invalid' : '' ?>"
                               value="<?= htmlspecialchars($old['name'] ?? '') ?>"
                               maxlength="64"
                               required
                               autofocus>
                        <?php if (isset($errors['name'])): ?>
                            <div class="invalid-feedback"><?= htmlspecialchars($errors['name']) ?></div>
                        <?php else: ?>
                            <div class="form-text">
                                Letters, numbers, dots, dashes, and underscores. Must be unique.
                            </div>
                        <?php endif; ?>
                    </div>

                    <div class="mb-4">
                        <label for="group-desc" class="form-label">Description</label>
                        <input type="text"
                               id="group-desc"
                               name="description"
                               class="form-control <?= isset($errors['description']) ? 'is-invalid' : '' ?>"
                               value="<?= htmlspecialchars($old['description'] ?? '') ?>"
                               maxlength="255">
                        <?php if (isset($errors['description'])): ?>
                            <div class="invalid-feedback"><?= htmlspecialchars($errors['description']) ?></div>
                        <?php endif; ?>
                    </div>

                    <div class="d-flex gap-2">
                        <button type="submit" class="btn btn-primary">Create Group</button>
                        <a href="/admin/groups" class="btn btn-outline-secondary">Cancel</a>
                    </div>

                </form>
            </div>
        </div>

    </div>
</div>
