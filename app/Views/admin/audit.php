<div class="card">
    <div class="card-header d-flex align-items-center justify-content-between">
        <span class="fw-semibold small">Audit Log</span>
        <span class="text-muted small">Last <?= count($auditRows) ?> entries</span>
    </div>
    <div class="card-body p-0">
        <div class="table-responsive">
            <table id="admin-audit-table" class="table table-hover mb-0 w-100">
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>Actor</th>
                        <th>Action</th>
                        <th>Entity</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($auditRows as $row):
                        $actor = $row['actor_display_name']
                            ? htmlspecialchars($row['actor_display_name'])
                            : ($row['actor_username']
                                ? '<code>' . htmlspecialchars($row['actor_username']) . '</code>'
                                : '<span class="text-muted fst-italic">system</span>');

                        $meta    = json_decode($row['meta'] ?? '{}', true) ?: [];
                        $summary = implode(', ', array_map(
                            fn($k, $v) => htmlspecialchars($k) . '=' . htmlspecialchars((string) (is_bool($v) ? ($v ? 'true' : 'false') : $v)),
                            array_keys($meta),
                            array_values($meta)
                        ));
                        if ($summary === '') $summary = '—';
                    ?>
                    <tr>
                        <td class="text-muted small" style="white-space:nowrap;">
                            <?= htmlspecialchars($row['created_at']) ?>
                        </td>
                        <td><?= $actor ?></td>
                        <td>
                            <code class="text-body"><?= htmlspecialchars($row['action']) ?></code>
                        </td>
                        <td class="text-muted small" style="white-space:nowrap;">
                            <?= htmlspecialchars($row['entity_type']) ?>
                            <span class="text-muted">#<?= (int) $row['entity_id'] ?></span>
                        </td>
                        <td class="text-muted small" style="max-width:360px; word-break:break-word;">
                            <?= $summary ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($auditRows)): ?>
                    <tr>
                        <td colspan="5" class="text-center text-muted small fst-italic py-4">
                            No audit log entries yet.
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
NetMon.dt.init('#admin-audit-table', {
    order: [[0, 'desc']],
    columnDefs: [
        { orderable: false, targets: [4] }
    ]
});
</script>
