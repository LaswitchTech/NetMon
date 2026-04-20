<div class="d-flex align-items-center justify-content-between mb-4">
    <h5 class="mb-0">File Manager</h5>
</div>

<?php if (empty($roots)): ?>
<div class="alert alert-warning">
    No storage roots are configured or enabled.
    Add an entry to <code>config/filemanager.php</code> to get started.
</div>
<?php else: ?>

<div class="row g-3">
    <?php foreach ($roots as $root): ?>
    <div class="col-md-6 col-lg-4">
        <div class="card h-100">
            <div class="card-body d-flex flex-column gap-2">
                <div class="d-flex align-items-center gap-2">
                    <i class="bi bi-hdd fs-4 text-body-secondary"></i>
                    <div>
                        <div class="fw-semibold"><?= htmlspecialchars($root['label']) ?></div>
                        <code class="text-muted" style="font-size:.75rem;">
                            <?= htmlspecialchars($root['id']) ?>
                        </code>
                    </div>
                </div>
                <p class="text-muted small mb-0" style="font-size:.8rem;">
                    <?= htmlspecialchars($root['path']) ?>
                </p>
            </div>
            <div class="card-footer">
                <a href="/files/<?= rawurlencode($root['id']) ?>"
                   class="btn btn-sm btn-primary w-100">
                    <i class="bi bi-folder2-open me-1"></i>Browse
                </a>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php endif; ?>
