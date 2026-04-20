<?php
/**
 * Discovery jobs list content fragment.
 *
 * Variables available (set by DiscoveryController::jobIndex before ob_start):
 *   $user        (array)   — safe user record from the principal
 *   $permissions (array)   — permission names for the authenticated user
 *   $appName     (string)  — application name from config
 *   $displayName (string)  — display_name if set, otherwise username
 *   $jobs        (array)   — rows from DiscoveryRepository::findAllJobs()
 *                            each row: id, name, subnet, enabled, last_run_at,
 *                            created_at, findings_count
 */
?>

<!-- Breadcrumb -->
<nav aria-label="breadcrumb" class="mb-4">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="/discovery">Discovery</a></li>
        <li class="breadcrumb-item active" aria-current="page">Jobs</li>
    </ol>
</nav>

<!-- Page heading -->
<div class="mb-4">
    <h1 class="h4 fw-semibold mb-1">Discovery Jobs</h1>
    <p class="text-muted mb-0 small">
        Subnet scan configurations. Each job defines a CIDR range that
        <code>scripts/discover.php</code> will probe for active hosts.
    </p>
</div>

<!-- Jobs table -->
<div class="card">
    <div class="table-responsive p-3">
        <table id="tbl-jobs" class="table table-hover align-middle mb-0 small w-100">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Subnet</th>
                    <th>Status</th>
                    <th>Findings</th>
                    <th>Last Run</th>
                    <th>Created</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
<?php foreach ($jobs as $job): ?>
                <tr>
                    <td class="fw-medium"><?= htmlspecialchars($job['name']) ?></td>
                    <td class="font-monospace"><?= htmlspecialchars($job['subnet']) ?></td>
                    <td>
                        <?php if ($job['enabled']): ?>
                            <span class="badge bg-success">Enabled</span>
                        <?php else: ?>
                            <span class="badge bg-secondary">Disabled</span>
                        <?php endif; ?>
                    </td>
                    <td><?= (int) $job['findings_count'] ?></td>
                    <td class="text-muted">
                        <?= $job['last_run_at'] !== null
                            ? htmlspecialchars($job['last_run_at'])
                            : '<span class="text-muted">Never</span>' ?>
                    </td>
                    <td class="text-muted"><?= htmlspecialchars($job['created_at']) ?></td>
                    <td class="text-end text-nowrap">
                        <a href="/discovery/jobs/<?= (int) $job['id'] ?>/edit"
                           class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-pencil me-1"></i>Edit
                        </a>
                        <button type="button"
                                class="btn btn-sm btn-outline-danger ms-1"
                                data-bs-toggle="modal"
                                data-bs-target="#modal-delete-<?= (int) $job['id'] ?>">
                            <i class="bi bi-trash me-1"></i>Delete
                        </button>
                    </td>
                </tr>
<?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Delete confirmation modals -->
<?php foreach ($jobs as $job): ?>
<div class="modal fade" id="modal-delete-<?= (int) $job['id'] ?>" tabindex="-1"
     aria-labelledby="modal-delete-label-<?= (int) $job['id'] ?>" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="modal-delete-label-<?= (int) $job['id'] ?>">
                    Delete Job
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p>
                    Delete job <strong><?= htmlspecialchars($job['name']) ?></strong>
                    (<code><?= htmlspecialchars($job['subnet']) ?></code>)?
                </p>
                <div class="alert alert-warning d-flex gap-2 align-items-start mb-0">
                    <i class="bi bi-exclamation-triangle-fill flex-shrink-0 mt-1"></i>
                    <span>
                        All <strong><?= (int) $job['findings_count'] ?></strong>
                        finding<?= (int) $job['findings_count'] !== 1 ? 's' : '' ?>
                        associated with this job will be permanently deleted.
                        This cannot be undone.
                    </span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" action="/discovery/jobs/<?= (int) $job['id'] ?>/delete">
                    <button type="submit" class="btn btn-danger">
                        <i class="bi bi-trash me-1"></i>Delete Job
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<?php endforeach; ?>

<script>
window.addEventListener('DOMContentLoaded', function () {
    var dt = NetMon.dt.init('#tbl-jobs', {
        pageLength : 25,
        order      : [[0, 'asc']],
        language   : { emptyTable: 'No discovery jobs configured yet.' },
        columnDefs : [
            { targets: -1, orderable: false, searchable: false },
        ],
    });

    dt.buttons().container().prepend(
        '<a href="/discovery/jobs/create" class="btn btn-sm btn-primary me-2">' +
        '<i class="bi bi-plus-lg me-1"></i>New Job</a>'
    );
});
</script>
