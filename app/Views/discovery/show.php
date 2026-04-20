<?php
/**
 * Discovery finding detail content fragment.
 *
 * Variables available (set by DiscoveryController::show before ob_start):
 *   $finding         (array)  — finding row with job_name, job_subnet, device_name
 *   $devices         (array)  — active devices list (for link-to-device dropdown)
 *   $possibleMatches (array)  — from DiscoveryRepository::possibleMatchesForFinding();
 *                               each entry: id, name, address, match_reason, match_value
 *                               empty array if no suggestions
 *   $notes           (array)  — rows from NoteRepository::findByEntity('finding', $findingId)
 *   $user, $permissions, $appName, $displayName
 */

$findingId = (int) $finding['id'];
$isPending = $finding['status'] === 'pending';
$isMatched = $finding['status'] === 'matched';
$isIgnored = $finding['status'] === 'ignored';

$statusBadge = match ($finding['status']) {
    'matched' => ['class' => 'bg-success',           'label' => 'Matched'],
    'pending' => ['class' => 'bg-warning text-dark', 'label' => 'Pending Review'],
    'ignored' => ['class' => 'bg-secondary',         'label' => 'Ignored'],
    default   => ['class' => 'bg-secondary',         'label' => htmlspecialchars($finding['status'])],
};

$hasError = isset($_GET['error']);
?>

<!-- Breadcrumb -->
<nav aria-label="breadcrumb" class="mb-4">
    <ol class="breadcrumb small">
        <li class="breadcrumb-item"><a href="/discovery">Discovery</a></li>
        <li class="breadcrumb-item active" aria-current="page">
            <?= htmlspecialchars($finding['ip_address']) ?>
        </li>
    </ol>
</nav>

<?php if ($hasError): ?>
<div class="alert alert-danger alert-dismissible small mb-4" role="alert">
    <?php if ($_GET['error'] === 'invalid_device'): ?>
        The selected device was not found or has been deleted. Please choose a valid device.
    <?php else: ?>
        An error occurred. Please try again.
    <?php endif; ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<!-- Page heading -->
<div class="mb-4">
    <h1 class="h4 fw-semibold mb-1">
        <span class="font-monospace"><?= htmlspecialchars($finding['ip_address']) ?></span>
        <span class="badge <?= $statusBadge['class'] ?> ms-2 fs-6 align-middle">
            <?= $statusBadge['label'] ?>
        </span>
    </h1>
    <p class="text-muted mb-0 small">
        Discovered by job <strong><?= htmlspecialchars($finding['job_name']) ?></strong>
        scanning <span class="font-monospace"><?= htmlspecialchars($finding['job_subnet']) ?></span>
    </p>
</div>

<div class="row g-4">

    <!-- ======================================================
         Left: Finding overview
         ====================================================== -->
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header bg-transparent pt-3 pb-2 px-4">
                <h2 class="h6 fw-semibold mb-0">Finding Details</h2>
            </div>
            <div class="card-body px-4 pb-4">
                <dl class="row mb-0" style="row-gap:.5rem">

                    <dt class="col-sm-4 text-muted small fw-normal">IP Address</dt>
                    <dd class="col-sm-8 mb-0 font-monospace">
                        <?= htmlspecialchars($finding['ip_address']) ?>
                    </dd>

                    <dt class="col-sm-4 text-muted small fw-normal">Hostname</dt>
                    <dd class="col-sm-8 mb-0">
                        <?php if (($finding['hostname'] ?? '') !== ''): ?>
                            <?= htmlspecialchars($finding['hostname']) ?>
                        <?php else: ?>
                            <span class="text-muted">Not resolved</span>
                        <?php endif; ?>
                    </dd>

                    <dt class="col-sm-4 text-muted small fw-normal">MAC Address</dt>
                    <dd class="col-sm-8 mb-0">
                        <?php if (($finding['mac_address'] ?? '') !== ''): ?>
                            <span class="font-monospace"><?= htmlspecialchars($finding['mac_address']) ?></span>
                        <?php else: ?>
                            <span class="text-muted">Not available</span>
                        <?php endif; ?>
                    </dd>

                    <dt class="col-sm-4 text-muted small fw-normal">Status</dt>
                    <dd class="col-sm-8 mb-0">
                        <span class="badge <?= $statusBadge['class'] ?>"><?= $statusBadge['label'] ?></span>
                    </dd>

                    <?php if ($isMatched && ($finding['device_name'] ?? '') !== ''): ?>
                    <dt class="col-sm-4 text-muted small fw-normal">Matched device</dt>
                    <dd class="col-sm-8 mb-0">
                        <a href="/devices/<?= (int) $finding['matched_device_id'] ?>" class="text-decoration-none">
                            <?= htmlspecialchars($finding['device_name']) ?>
                        </a>
                    </dd>
                    <?php endif; ?>

                    <dt class="col-sm-4 text-muted small fw-normal">Discovery job</dt>
                    <dd class="col-sm-8 mb-0"><?= htmlspecialchars($finding['job_name']) ?></dd>

                    <dt class="col-sm-4 text-muted small fw-normal">Subnet</dt>
                    <dd class="col-sm-8 mb-0 font-monospace">
                        <?= htmlspecialchars($finding['job_subnet']) ?>
                    </dd>

                    <dt class="col-sm-4 text-muted small fw-normal">First seen</dt>
                    <dd class="col-sm-8 mb-0 small"><?= htmlspecialchars($finding['created_at']) ?></dd>

                </dl>
            </div>
        </div>
    </div>

    <!-- ======================================================
         Right: Actions
         ====================================================== -->
    <div class="col-lg-6">
        <div class="card h-100">
            <div class="card-header bg-transparent pt-3 pb-2 px-4">
                <h2 class="h6 fw-semibold mb-0">Actions</h2>
            </div>
            <div class="card-body px-4 pb-4">

                <?php if ($isIgnored): ?>
                <!-- Ignored: no actions available -->
                <p class="text-muted small mb-0">
                    <i class="bi bi-slash-circle me-1"></i>
                    This finding has been ignored. It remains visible for historical reference
                    but will not appear in pending-review counts.
                </p>

                <?php else: ?>

                <!-- ── Link to an existing device ───────────────────── -->
                <div class="mb-4">
                    <h3 class="h6 fw-medium mb-1">
                        <?= $isMatched ? 'Re-link to a device' : 'Link to an existing device' ?>
                    </h3>
                    <p class="small text-muted mb-2">
                        Mark this finding as belonging to a device already in the inventory.
                        <?php if ($isMatched): ?>
                            Use this to correct an incorrect match.
                        <?php endif; ?>
                        <strong>The device's address list is not modified by this action.</strong>
                        To record the IP on the device, add it via
                        <?php if ($isMatched && ($finding['matched_device_id'] ?? null)): ?>
                            <a href="/devices/<?= (int) $finding['matched_device_id'] ?>/edit">the device's edit page</a>.
                        <?php else: ?>
                            the device's edit page.
                        <?php endif; ?>
                    </p>

                    <?php if (empty($devices)): ?>
                    <p class="small text-muted fst-italic mb-0">
                        No active devices in inventory.
                        <a href="/devices/create">Add a device</a> first.
                    </p>
                    <?php else: ?>
                    <form method="POST" action="/discovery/<?= $findingId ?>/link" class="d-flex gap-2 align-items-start flex-wrap">
                        <div class="flex-fill" style="min-width: 200px">
                            <select name="device_id" class="form-select form-select-sm" required>
                                <option value="">— Select device —</option>
                                <?php foreach ($devices as $dev): ?>
                                <option value="<?= (int) $dev['id'] ?>"
                                    <?= (int) $dev['id'] === (int) ($finding['matched_device_id'] ?? 0) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($dev['name']) ?>
                                    <?php if (($dev['address'] ?? '') !== ''): ?>
                                        (<?= htmlspecialchars($dev['address']) ?>)
                                    <?php endif; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <button type="submit" class="btn btn-sm btn-primary">
                            <i class="bi bi-link-45deg me-1"></i>Link
                        </button>
                    </form>
                    <?php endif; ?>
                </div>

                <!-- ── Create new device from this finding ─────────── -->
                <?php if (!$isMatched): ?>
                <div class="mb-4 pb-3 border-bottom">
                    <h3 class="h6 fw-medium mb-1">Create a new device</h3>
                    <p class="small text-muted mb-2">
                        Register this IP as a new device. The IP address will be added to the
                        new device's management interface automatically.
                    </p>
                    <a href="/discovery/<?= $findingId ?>/create-device"
                       class="btn btn-sm btn-outline-primary">
                        <i class="bi bi-plus-lg me-1"></i>Create device from this finding
                    </a>
                </div>
                <?php endif; ?>

                <!-- ── Ignore ────────────────────────────────────────── -->
                <div>
                    <h3 class="h6 fw-medium mb-1">Ignore this finding</h3>
                    <p class="small text-muted mb-2">
                        Dismiss this finding. It will remain in history but will no longer
                        appear in the pending-review count. This cannot be undone from the UI.
                    </p>
                    <form method="POST" action="/discovery/<?= $findingId ?>/ignore"
                          onsubmit="return confirm('Ignore this finding? It will be excluded from pending review.')">
                        <button type="submit" class="btn btn-sm btn-outline-secondary">
                            <i class="bi bi-slash-circle me-1"></i>Ignore
                        </button>
                    </form>
                </div>

                <?php endif; // not ignored ?>

            </div>
        </div>
    </div>

</div>

<?php if (!empty($possibleMatches)): ?>
<!-- Possible matches (suggestion only — never automatic) -->
<div class="card card-accent-warning mt-4">
    <div class="card-header bg-transparent pt-3 pb-2 px-4">
        <div class="d-flex align-items-start gap-2">
            <i class="bi bi-lightbulb text-warning mt-1 flex-shrink-0"></i>
            <div>
                <h2 class="h6 fw-semibold mb-0">Possible Device Matches</h2>
                <p class="text-muted small mb-0">
                    The following devices share a strong identity signal with this finding.
                    These are <strong>suggestions only</strong>.
                    No link or merge is performed automatically — use the action panel above to decide.
                </p>
            </div>
        </div>
    </div>
    <div class="card-body px-4 pb-4 pt-0">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-2">
                <thead>
                    <tr>
                        <th style="width:28%">Device</th>
                        <th style="width:22%">Address</th>
                        <th style="width:22%">Signal</th>
                        <th>Matched Value</th>
                    </tr>
                </thead>
                <tbody>
<?php foreach ($possibleMatches as $match): ?>
<?php
    $isMac = $match['match_reason'] === 'mac';
    if ($isMac) {
        $signalBadge = '<span class="badge bg-warning text-dark" style="font-size:.68rem">MAC match</span>';
        $signalNote  = '<span class="d-block text-muted small">strong signal</span>';
    } else {
        $signalBadge = '<span class="badge bg-secondary" style="font-size:.68rem">Hostname match</span>';
        $signalNote  = '<span class="d-block text-muted small fst-italic">weaker &mdash; not unique</span>';
    }
?>
                    <tr>
                        <td class="fw-medium">
                            <a href="/devices/<?= (int) $match['id'] ?>" class="text-decoration-none">
                                <?= htmlspecialchars($match['name']) ?>
                            </a>
                        </td>
                        <td class="font-monospace small text-muted">
                            <?= htmlspecialchars($match['address'] ?? '—') ?>
                        </td>
                        <td>
                            <?= $signalBadge ?>
                            <?= $signalNote ?>
                        </td>
                        <td class="font-monospace small">
                            <?= htmlspecialchars($match['match_value']) ?>
                        </td>
                    </tr>
<?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <p class="text-muted small mb-0">
            <i class="bi bi-info-circle me-1"></i>
            To act on a suggestion: use <strong>Link to an existing device</strong> above to associate
            this finding with the matched device, or visit the device page to initiate a merge if
            you believe it represents a duplicate.
            MAC matches are strong but not infallible (NICs can be replaced).
            Hostname matches are heuristic — the same hostname may appear on different subnets or hosts.
        </p>
    </div>
</div>
<?php endif; ?>

<?php
// ── Notes section ─────────────────────────────────────────────────────────
$noteBaseUrl = '/discovery/' . $findingId;
require __DIR__ . '/../partials/notes-section.php';
?>
