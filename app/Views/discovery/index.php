<?php
/**
 * Discovery findings content fragment.
 *
 * Variables available (set by DiscoveryController before ob_start):
 *   $user        (array)              — safe user record from the principal
 *   $permissions (array)              — permission names for the authenticated user
 *   $appName     (string)             — application name from config
 *   $displayName (string)             — display_name if set, otherwise username
 *   $findings    (array)              — rows from DiscoveryRepository::findAllFindings(100)
 *                                       each row: id, job_id, ip_address, mac_address,
 *                                       hostname, status, matched_device_id, created_at,
 *                                       job_name, device_name
 *   $counts      (array<string,int>)  — counts per status: ['pending'=>N, 'matched'=>N, ...]
 */

$total   = count($findings);
$pending = $counts['pending']  ?? 0;
$matched = $counts['matched']  ?? 0;
$ignored = $counts['ignored']  ?? 0;
?>

<!-- Page heading -->
<div class="mb-4">
    <h1 class="h4 fw-semibold mb-1">Discovery</h1>
    <p class="text-muted mb-0 small">
        Hosts observed during subnet scans. Findings are informational — devices are never
        created or modified automatically.
        Run <code class="bg-light px-1 rounded">php scripts/discover.php</code> to scan.
    </p>
</div>

<!-- Status strip -->
<div class="row g-3 mb-4">

    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded p-2 bg-primary bg-opacity-10 text-primary flex-shrink-0">
                    <i class="bi bi-search fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small">Total Findings</div>
                    <div class="fw-semibold fs-5"><?= $total ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <?php $pendingColor = $pending > 0 ? 'warning' : 'secondary'; ?>
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded p-2 bg-<?= $pendingColor ?> bg-opacity-10 text-<?= $pendingColor ?> flex-shrink-0">
                    <i class="bi bi-hourglass-split fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small">Pending Review</div>
                    <div class="fw-semibold fs-5 <?= $pending > 0 ? 'text-warning' : '' ?>"><?= $pending ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded p-2 bg-success bg-opacity-10 text-success flex-shrink-0">
                    <i class="bi bi-link-45deg fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small">Matched</div>
                    <div class="fw-semibold fs-5 text-success"><?= $matched ?></div>
                </div>
            </div>
        </div>
    </div>

    <div class="col-sm-6 col-xl-3">
        <div class="card border-0 shadow-sm h-100">
            <div class="card-body d-flex align-items-center gap-3">
                <div class="rounded p-2 bg-secondary bg-opacity-10 text-secondary flex-shrink-0">
                    <i class="bi bi-slash-circle fs-4"></i>
                </div>
                <div>
                    <div class="text-muted small">Ignored</div>
                    <div class="fw-semibold fs-5"><?= $ignored ?></div>
                </div>
            </div>
        </div>
    </div>

</div>

<!-- Findings table -->
<div class="card border-0 shadow-sm">
    <div class="card-header bg-white border-bottom d-flex align-items-center justify-content-between py-2 px-3">
        <span class="fw-medium">
            Findings
            <?php if ($total > 0): ?>
                <span class="text-muted fw-normal small ms-1">(most recent <?= $total ?>)</span>
            <?php endif; ?>
        </span>
    </div>

    <?php if (empty($findings)): ?>
    <div class="card-body text-center py-5 text-muted">
        <i class="bi bi-radar opacity-25 d-block mb-2" style="font-size: 2.5rem"></i>
        No findings yet.
        <br>
        <span class="small mt-1 d-block">
            Create a discovery job and run
            <code class="bg-light px-1 rounded">php scripts/discover.php</code>
            to start scanning.
        </span>
    </div>
    <?php else: ?>
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 small">
            <thead class="table-light">
                <tr>
                    <th class="ps-3" style="width:15%">IP Address</th>
                    <th style="width:16%">Hostname</th>
                    <th style="width:15%">MAC Address</th>
                    <th style="width:14%">Job</th>
                    <th style="width:10%">Status</th>
                    <th style="width:18%">Matched Device</th>
                    <th style="width:12%" class="text-end pe-3">First Seen</th>
                </tr>
            </thead>
            <tbody>
<?php foreach ($findings as $finding): ?>
<?php
    $statusBadge = match ($finding['status']) {
        'matched' => ['class' => 'bg-success',            'label' => 'Matched'],
        'pending' => ['class' => 'bg-warning text-dark',  'label' => 'Pending'],
        'ignored' => ['class' => 'bg-secondary',          'label' => 'Ignored'],
        default   => ['class' => 'bg-secondary',          'label' => htmlspecialchars($finding['status'])],
    };

    $hasDevice = isset($finding['device_name']) && $finding['device_name'] !== null;

    $deviceCell = $hasDevice
        ? '<a href="/devices/' . (int) $finding['matched_device_id'] . '" class="text-decoration-none">'
          . htmlspecialchars($finding['device_name']) . '</a>'
        : '<span class="text-muted">&mdash;</span>';

    $hostnameCell = ($finding['hostname'] ?? '') !== ''
        ? htmlspecialchars($finding['hostname'])
        : '<span class="text-muted">&mdash;</span>';

    $macCell = ($finding['mac_address'] ?? '') !== ''
        ? '<span class="font-monospace">' . htmlspecialchars($finding['mac_address']) . '</span>'
        : '<span class="text-muted">&mdash;</span>';
?>
            <tr>
                <td class="ps-3 font-monospace fw-medium">
                    <a href="/discovery/<?= (int) $finding['id'] ?>" class="text-decoration-none">
                        <?= htmlspecialchars($finding['ip_address']) ?>
                    </a>
                </td>
                <td><?= $hostnameCell ?></td>
                <td class="small"><?= $macCell ?></td>
                <td class="text-muted"><?= htmlspecialchars($finding['job_name']) ?></td>
                <td>
                    <a href="/discovery/<?= (int) $finding['id'] ?>" class="text-decoration-none">
                        <span class="badge <?= $statusBadge['class'] ?>">
                            <?= $statusBadge['label'] ?>
                        </span>
                    </a>
                </td>
                <td><?= $deviceCell ?></td>
                <td class="text-muted text-end pe-3">
                    <?= htmlspecialchars($finding['created_at']) ?>
                </td>
            </tr>
<?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>
