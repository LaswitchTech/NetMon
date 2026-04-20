<?php
/**
 * Notification inbox content fragment.
 *
 * Variables available (set by NotificationController::index before ob_start):
 *   $user            (array)   — authenticated user record
 *   $permissions     (array)   — permission names for the authenticated user
 *   $appName         (string)  — application name from config
 *   $displayName     (string)  — display_name if set, otherwise username
 *   $notifications   (array)   — in_app delivery rows (read + unread), newest first
 *                                each row includes: id, title, body, read_at, created_at,
 *                                source_type, source_id, data
 *   $unreadCount     (int)     — number of unread in_app notifications
 *
 * Design note:
 *   Notifications are rendered as cards rather than a DataTables table.
 *   Each notification has a variable-length body and a primary action button
 *   (Mark as read) that does not fit cleanly in a tabular layout.
 *   This is an intentional exception to the DataTables default, consistent
 *   with the Notes module pattern.
 */
?>

<!-- Page heading -->
<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h1 class="h4 fw-semibold mb-1">Notifications</h1>
        <?php if ($unreadCount > 0): ?>
        <p class="text-muted small mb-0">
            <?= $unreadCount ?> unread notification<?= $unreadCount !== 1 ? 's' : '' ?>
        </p>
        <?php else: ?>
        <p class="text-muted small mb-0">All caught up.</p>
        <?php endif; ?>
    </div>

    <?php if ($unreadCount > 0): ?>
    <form method="post" action="/notifications/read-all">
        <button type="submit" class="btn btn-sm btn-outline-secondary">
            <i class="bi bi-check2-all me-1"></i>Mark all as read
        </button>
    </form>
    <?php endif; ?>
</div>

<?php if (empty($notifications)): ?>

<!-- Empty state -->
<div class="card">
    <div class="card-body text-center py-5 text-muted">
        <i class="bi bi-bell-slash" style="font-size:2rem; opacity:.4;"></i>
        <p class="mt-3 mb-0 small">No notifications yet.</p>
    </div>
</div>

<?php else: ?>

<div class="notification-list">
<?php foreach ($notifications as $n): ?>
<?php
    $isUnread   = $n['read_at'] === null;
    $dataPayload = null;
    if ($n['data'] !== null) {
        $dataPayload = json_decode($n['data'], true);
    }
?>

<div class="card mb-2 <?= $isUnread ? 'border-primary' : '' ?>"
     style="<?= $isUnread ? 'border-left-width:3px;' : 'opacity:.75;' ?>">
    <div class="card-body py-3 px-4">
        <div class="d-flex justify-content-between align-items-start gap-3">

            <!-- Left: icon + content -->
            <div class="d-flex gap-3 align-items-start flex-fill min-width-0">

                <!-- Unread dot indicator -->
                <div class="flex-shrink-0 mt-1" style="width:0.6rem;">
                    <?php if ($isUnread): ?>
                    <span class="d-inline-block rounded-circle bg-primary"
                          style="width:0.5rem; height:0.5rem;" title="Unread"></span>
                    <?php endif; ?>
                </div>

                <!-- Notification content -->
                <div class="flex-fill min-width-0">
                    <div class="d-flex align-items-baseline gap-2 mb-1 flex-wrap">
                        <span class="fw-semibold <?= $isUnread ? '' : 'text-muted' ?> small">
                            <?= htmlspecialchars($n['title']) ?>
                        </span>
                        <?php if ($n['source_type'] !== null): ?>
                        <span class="badge bg-secondary text-uppercase"
                              style="font-size:.6rem; letter-spacing:.04em;">
                            <?= htmlspecialchars($n['source_type']) ?>
                        </span>
                        <?php endif; ?>
                    </div>
                    <p class="small text-muted mb-1" style="white-space: pre-wrap; word-break: break-word;">
                        <?= htmlspecialchars($n['body']) ?>
                    </p>
                    <div class="small text-muted" style="font-size:.75rem;">
                        <?= htmlspecialchars($n['created_at']) ?>
                        <?php if (!$isUnread): ?>
                        &middot; Read <?= htmlspecialchars($n['read_at']) ?>
                        <?php endif; ?>
                    </div>
                </div>

            </div>

            <!-- Right: actions -->
            <?php if ($isUnread): ?>
            <div class="flex-shrink-0">
                <form method="post" action="/notifications/<?= (int) $n['id'] ?>/read">
                    <button type="submit"
                            class="btn btn-sm btn-link p-0 text-muted"
                            title="Mark as read"
                            style="font-size:.8rem; white-space:nowrap;">
                        <i class="bi bi-check2 me-1"></i>Mark read
                    </button>
                </form>
            </div>
            <?php endif; ?>

        </div>
    </div>
</div>

<?php endforeach; ?>
</div>

<?php endif; ?>
