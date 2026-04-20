<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?> alert-dismissible mb-4" role="alert">
    <?= htmlspecialchars($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <span class="fw-semibold small">All Permissions</span>
        <a href="/admin/permissions/create" class="btn btn-sm btn-primary">
            <i class="bi bi-plus-lg me-1"></i>New Permission
        </a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table id="admin-permissions-table" class="table table-hover mb-0 w-100">
                <thead>
                    <tr>
                        <th>Code</th>
                        <th>Description</th>
                        <th>Groups</th>
                        <th>Created</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($permList as $p): ?>
                    <tr>
                        <td>
                            <code><?= htmlspecialchars($p['name']) ?></code>
                        </td>
                        <td class="text-muted small">
                            <?= $p['description'] !== null ? htmlspecialchars($p['description']) : '<span class="text-muted fst-italic">—</span>' ?>
                        </td>
                        <td>
                            <span class="badge bg-body-secondary text-body border">
                                <?= (int) $p['group_count'] ?>
                            </span>
                        </td>
                        <td class="text-muted small">
                            <?= htmlspecialchars(substr($p['created_at'] ?? '', 0, 10)) ?>
                        </td>
                        <td>
                            <a href="/admin/permissions/<?= (int) $p['id'] ?>/edit"
                               class="btn btn-sm btn-outline-secondary"
                               title="Edit">
                                <i class="bi bi-pencil"></i>
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
NetMon.dt.init('#admin-permissions-table', {
    order: [[0, 'asc']],
    columnDefs: [
        { orderable: false, targets: [4] }
    ]
});
</script>
