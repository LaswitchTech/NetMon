<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?> alert-dismissible mb-4" role="alert">
    <?= htmlspecialchars($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <span class="fw-semibold small">All Users</span>
        <a href="/admin/users/create" class="btn btn-sm btn-primary">
            <i class="bi bi-person-plus me-1"></i>Create User
        </a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table id="admin-users-table" class="table table-hover mb-0 w-100">
                <thead>
                    <tr>
                        <th>Display Name</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Status</th>
                        <th>Created</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($users as $u): ?>
                    <tr>
                        <td><?= htmlspecialchars($u['display_name']) ?></td>
                        <td>
                            <code class="text-muted"><?= htmlspecialchars($u['username']) ?></code>
                        </td>
                        <td><?= htmlspecialchars($u['email']) ?></td>
                        <td>
                            <?php if ($u['is_active']): ?>
                                <span class="badge bg-success-subtle text-success border border-success-subtle">Active</span>
                            <?php else: ?>
                                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle">Inactive</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted small">
                            <?= htmlspecialchars(substr($u['created_at'], 0, 10)) ?>
                        </td>
                        <td class="text-end" style="white-space:nowrap;">
                            <a href="/admin/users/<?= (int) $u['id'] ?>/edit-account"
                               class="btn btn-sm btn-outline-secondary"
                               title="Edit account details">
                                <i class="bi bi-person-gear"></i>
                            </a>
                            <a href="/admin/users/<?= (int) $u['id'] ?>/edit"
                               class="btn btn-sm btn-outline-secondary"
                               title="Edit group membership">
                                <i class="bi bi-people"></i>
                            </a>
                            <?php if ($u['is_active']): ?>
                            <form method="POST" action="/admin/users/<?= (int) $u['id'] ?>/deactivate"
                                  class="d-inline"
                                  onsubmit="return confirm('Deactivate <?= htmlspecialchars(addslashes($u['display_name'])) ?>? They will no longer be able to log in.')">
                                <button type="submit" class="btn btn-sm btn-outline-warning" title="Deactivate">
                                    <i class="bi bi-person-slash"></i>
                                </button>
                            </form>
                            <?php else: ?>
                            <form method="POST" action="/admin/users/<?= (int) $u['id'] ?>/activate"
                                  class="d-inline">
                                <button type="submit" class="btn btn-sm btn-outline-success" title="Activate">
                                    <i class="bi bi-person-check"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
NetMon.dt.init('#admin-users-table', {
    order: [[0, 'asc']],
    columnDefs: [
        { orderable: false, targets: [3, 5] }
    ]
});
</script>
