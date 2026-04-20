<?php
$systemGroups = \App\Models\GroupRepository::SYSTEM_GROUPS;
?>

<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?> alert-dismissible mb-4" role="alert">
    <?= htmlspecialchars($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <span class="fw-semibold small">All Groups</span>
        <a href="/admin/groups/create" class="btn btn-primary btn-sm">
            <i class="bi bi-plus-lg me-1"></i>New Group
        </a>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table id="admin-groups-table" class="table table-hover mb-0 w-100">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Description</th>
                        <th>Members</th>
                        <th>Permissions</th>
                        <th>Created</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($groups as $g): ?>
                    <?php $isSystem = in_array(strtolower($g['name']), $systemGroups, true); ?>
                    <tr>
                        <td class="fw-semibold">
                            <?= htmlspecialchars($g['name']) ?>
                            <?php if ($isSystem): ?>
                                <span class="badge bg-secondary-subtle text-secondary border border-secondary-subtle ms-1"
                                      title="System group — cannot be deleted">system</span>
                            <?php endif; ?>
                        </td>
                        <td class="text-muted small">
                            <?= $g['description'] !== null ? htmlspecialchars($g['description']) : '<span class="fst-italic">—</span>' ?>
                        </td>
                        <td>
                            <span class="badge bg-body-secondary text-body border">
                                <?= (int) $g['member_count'] ?>
                            </span>
                        </td>
                        <td>
                            <span class="badge bg-body-secondary text-body border">
                                <?= (int) $g['permission_count'] ?>
                            </span>
                        </td>
                        <td class="text-muted small">
                            <?= htmlspecialchars(substr($g['created_at'], 0, 10)) ?>
                        </td>
                        <td class="text-end" style="white-space:nowrap;">
                            <a href="/admin/groups/<?= (int) $g['id'] ?>/edit"
                               class="btn btn-sm btn-outline-secondary me-1">
                                <i class="bi bi-pencil"></i>
                            </a>
                            <?php if (!$isSystem): ?>
                            <form method="POST"
                                  action="/admin/groups/<?= (int) $g['id'] ?>/delete"
                                  class="d-inline js-delete-form">
                                <button type="submit" class="btn btn-sm btn-outline-danger"
                                        title="Delete group">
                                    <i class="bi bi-trash"></i>
                                </button>
                            </form>
                            <?php else: ?>
                            <button class="btn btn-sm btn-outline-danger" disabled title="System group cannot be deleted">
                                <i class="bi bi-trash"></i>
                            </button>
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
NetMon.dt.init('#admin-groups-table', {
    order: [[0, 'asc']],
    columnDefs: [
        { orderable: false, targets: [2, 3, 5] }
    ]
});

// Confirm before delete
document.querySelectorAll('.js-delete-form').forEach(function (form) {
    form.addEventListener('submit', function (e) {
        if (!confirm('Delete this group? All memberships and permission grants for this group will also be removed. This cannot be undone.')) {
            e.preventDefault();
        }
    });
});
</script>
