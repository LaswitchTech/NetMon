<?php
/**
 * Reusable notes section partial.
 *
 * Variables expected (set by the including view):
 *   $notes       (array)   — rows from NoteRepository::findByEntity (may be empty)
 *   $noteBaseUrl (string)  — URL prefix for note actions, e.g. '/devices/5' or '/alerts/3'
 *   $user        (array)   — authenticated user record (must contain 'id')
 *
 * Notes are rendered without DataTables — each note is a prose block with
 * variable content length, which does not fit a tabular layout.
 * This is an intentional exception to the DataTables default.
 */
?>

<!-- ── Notes ─────────────────────────────────────────────────────────────── -->
<div class="card mb-3" id="notes">
    <div class="card-body">
        <h6 class="section-label mb-3">Notes</h6>

        <?php if (!empty($_GET['note_error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show mb-3" role="alert">
            <?= htmlspecialchars($_GET['note_error']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
        <?php endif; ?>

        <?php if (empty($notes)): ?>
        <p class="small text-muted mb-3">No notes yet. Add one below.</p>
        <?php else: ?>
        <div class="mb-4">
            <?php foreach ($notes as $note): ?>
            <?php
                // Resolve display name: prefer display_name, fall back to username,
                // fall back to "(deleted user)" if account no longer exists.
                $noteAuthor = '(deleted user)';
                if (!empty($note['author_display'])) {
                    $noteAuthor = $note['author_display'];
                } elseif (!empty($note['author_name'])) {
                    $noteAuthor = $note['author_name'];
                }

                $isOwn    = $note['user_id'] !== null && (int) $note['user_id'] === (int) $user['id'];
                $isEdited = $note['updated_at'] !== $note['created_at'];
            ?>
            <div class="p-3 mb-2 rounded" style="background:var(--app-panel-2); border:1px solid var(--app-border);">
                <div class="d-flex justify-content-between align-items-start mb-2">
                    <div class="small text-muted">
                        <span class="fw-medium text-body"><?= htmlspecialchars($noteAuthor) ?></span>
                        &middot;
                        <?= htmlspecialchars($note['created_at']) ?>
                        <?php if ($isEdited): ?>
                        <span class="ms-1 fst-italic">(edited)</span>
                        <?php endif; ?>
                    </div>
                    <?php if ($isOwn): ?>
                    <form method="post"
                          action="<?= htmlspecialchars($noteBaseUrl) ?>/notes/<?= (int) $note['id'] ?>/delete"
                          class="d-inline ms-2">
                        <button type="submit"
                                class="btn btn-link btn-sm p-0 text-danger"
                                title="Delete note"
                                onclick="return confirm('Delete this note? This cannot be undone.');">
                            <i class="bi bi-trash" style="font-size:.8rem;"></i>
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
                <div class="small" style="white-space: pre-wrap; word-break: break-word;"><?= htmlspecialchars($note['content']) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <!-- Add note form -->
        <form method="post" action="<?= htmlspecialchars($noteBaseUrl) ?>/notes" novalidate>
            <div class="mb-2">
                <label for="note-content" class="form-label fw-medium small">Add a note</label>
                <textarea class="form-control form-control-sm"
                          id="note-content" name="content"
                          rows="3"
                          maxlength="10000"
                          placeholder="Enter a note…"></textarea>
            </div>
            <button type="submit" class="btn btn-sm btn-primary">
                <i class="bi bi-plus-lg me-1"></i>Add Note
            </button>
        </form>
    </div>
</div>
