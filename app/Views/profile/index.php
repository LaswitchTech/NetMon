<?php
/**
 * Profile page content fragment.
 *
 * Variables available (set by ProfileController::index before ob_start):
 *   $user          (array)   — authenticated user record (id, username, display_name, email)
 *   $permissions   (array)   — permission names for the authenticated user
 *   $appName       (string)  — application name from config
 *   $displayName   (string)  — display_name if set, otherwise username
 *   $notifPrefs    (array)   — ['in_app' => bool, 'email' => bool]
 *   $flash         (array|null) — one-time status message ['type', 'message']
 */
?>

<div class="d-flex align-items-center justify-content-between mb-4">
    <div>
        <h1 class="h4 fw-semibold mb-1">Profile</h1>
        <p class="text-muted small mb-0">Your account settings and preferences.</p>
    </div>
</div>

<?php if ($flash !== null): ?>
<div class="alert alert-<?= htmlspecialchars($flash['type']) ?> alert-dismissible mb-4" role="alert">
    <?= htmlspecialchars($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<!-- ── Account summary ─────────────────────────────────────────────────── -->
<div class="card mb-4">
    <div class="card-header fw-semibold">
        <i class="bi bi-person-circle me-2 text-muted"></i>Account
    </div>
    <div class="card-body">
        <dl class="row mb-0" style="row-gap:.5rem;">
            <dt class="col-sm-3 text-muted small fw-normal">Display name</dt>
            <dd class="col-sm-9 mb-0 small">
                <?= htmlspecialchars(($user['display_name'] ?? '') !== '' ? $user['display_name'] : '—') ?>
            </dd>
            <dt class="col-sm-3 text-muted small fw-normal">Username</dt>
            <dd class="col-sm-9 mb-0 small"><?= htmlspecialchars($user['username']) ?></dd>
            <dt class="col-sm-3 text-muted small fw-normal">Email</dt>
            <dd class="col-sm-9 mb-0 small">
                <?= ($user['email'] ?? '') !== '' ? htmlspecialchars($user['email']) : '—' ?>
            </dd>
        </dl>
    </div>
</div>

<!-- ── Notification preferences ────────────────────────────────────────── -->
<div class="card mb-4" id="notification-preferences">
    <div class="card-header fw-semibold">
        <i class="bi bi-bell me-2 text-muted"></i>Notification delivery
    </div>
    <div class="card-body">
        <p class="text-muted small mb-3">
            Choose which channels you want to receive notifications on.
            In-app notifications appear in the bell menu in the topbar.
            Email notifications are sent to your registered email address when the
            email channel is configured by your administrator.
        </p>

        <form method="post" action="/profile/notification-preferences">
            <div class="d-flex flex-column gap-3 mb-4">

                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox"
                           role="switch" id="pref-in-app" name="in_app"
                           <?= $notifPrefs['in_app'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="pref-in-app">
                        <span class="small fw-medium">In-app notifications</span>
                        <span class="d-block text-muted" style="font-size:.8rem;">
                            Alerts and status updates in the topbar bell.
                        </span>
                    </label>
                </div>

                <div class="form-check form-switch">
                    <input class="form-check-input" type="checkbox"
                           role="switch" id="pref-email" name="email"
                           <?= $notifPrefs['email'] ? 'checked' : '' ?>>
                    <label class="form-check-label" for="pref-email">
                        <span class="small fw-medium">Email notifications</span>
                        <span class="d-block text-muted" style="font-size:.8rem;">
                            Delivered to <?= ($user['email'] ?? '') !== ''
                                ? '<strong>' . htmlspecialchars($user['email']) . '</strong>'
                                : 'your email address (not set)' ?>.
                        </span>
                    </label>
                </div>

            </div>

            <button type="submit" class="btn btn-sm btn-primary">
                <i class="bi bi-check2 me-1"></i>Save preferences
            </button>
        </form>
    </div>
</div>

<!-- ── API tokens ────────────────────────────────────────────────────── -->
<div class="card" id="api-tokens">
    <div class="card-header fw-semibold d-flex align-items-center justify-content-between">
        <span><i class="bi bi-key me-2 text-muted"></i>API tokens</span>
        <button class="btn btn-sm btn-outline-primary" id="js-new-token-btn">
            <i class="bi bi-plus-lg me-1"></i>New token
        </button>
    </div>
    <div class="card-body">

        <p class="text-muted small mb-3">
            API tokens let external tools and scripts authenticate against the NetMon API.
            A token is shown <strong>once</strong> at creation — copy it immediately.
        </p>

        <!-- New token form (hidden by default) -->
        <div id="js-new-token-form" class="card mb-4 border-primary" style="display:none !important;">
            <div class="card-body">
                <h6 class="fw-semibold mb-3">Create new token</h6>
                <div class="mb-3">
                    <label for="js-token-name" class="form-label small">Token name</label>
                    <input type="text" class="form-control form-control-sm"
                           id="js-token-name" placeholder="e.g. Grafana integration"
                           maxlength="100" autocomplete="off">
                </div>
                <div class="mb-3">
                    <label for="js-token-expires" class="form-label small">
                        Expires at <span class="text-muted">(optional)</span>
                    </label>
                    <input type="datetime-local" class="form-control form-control-sm"
                           id="js-token-expires">
                </div>
                <div class="d-flex gap-2">
                    <button class="btn btn-sm btn-primary" id="js-token-create-btn">Create</button>
                    <button class="btn btn-sm btn-outline-secondary" id="js-token-cancel-btn">Cancel</button>
                </div>
                <div id="js-token-error" class="text-danger small mt-2" style="display:none;"></div>
            </div>
        </div>

        <!-- Newly created token reveal (one-time) -->
        <div id="js-token-reveal" class="alert alert-success" style="display:none;">
            <div class="d-flex align-items-start gap-3">
                <i class="bi bi-check-circle-fill mt-1 flex-shrink-0"></i>
                <div class="flex-fill min-width-0">
                    <p class="mb-1 fw-semibold small">Token created — copy it now.</p>
                    <p class="text-muted small mb-2">
                        This value will not be shown again. Store it somewhere safe.
                    </p>
                    <div class="input-group input-group-sm">
                        <input type="text" class="form-control font-monospace"
                               id="js-token-value" readonly>
                        <button class="btn btn-outline-success"
                                id="js-token-copy-btn" title="Copy to clipboard">
                            <i class="bi bi-clipboard"></i>
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <!-- Token list -->
        <div id="js-token-list">
            <div class="text-muted small text-center py-3" id="js-token-loading">
                <span class="spinner-border spinner-border-sm me-2" role="status"></span>
                Loading tokens…
            </div>
        </div>

    </div>
</div>

<script>
(function () {
    var newTokenBtn    = document.getElementById('js-new-token-btn');
    var newTokenForm   = document.getElementById('js-new-token-form');
    var tokenName      = document.getElementById('js-token-name');
    var tokenExpires   = document.getElementById('js-token-expires');
    var createBtn      = document.getElementById('js-token-create-btn');
    var cancelBtn      = document.getElementById('js-token-cancel-btn');
    var tokenError     = document.getElementById('js-token-error');
    var tokenReveal    = document.getElementById('js-token-reveal');
    var tokenValue     = document.getElementById('js-token-value');
    var tokenCopyBtn   = document.getElementById('js-token-copy-btn');
    var tokenList      = document.getElementById('js-token-list');
    var tokenLoading   = document.getElementById('js-token-loading');

    // ── Load tokens ───────────────────────────────────────────────────────
    function loadTokens() {
        fetch('/api/tokens', { credentials: 'same-origin' })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                tokenLoading.style.display = 'none';
                renderTokens(data.tokens || []);
            })
            .catch(function () {
                tokenLoading.innerHTML =
                    '<span class="text-danger small">Failed to load tokens.</span>';
            });
    }

    function renderTokens(tokens) {
        // Remove any existing token rows (not the loading indicator or reveal).
        var existing = tokenList.querySelectorAll('.token-row');
        existing.forEach(function (el) { el.remove(); });

        if (tokens.length === 0) {
            var empty = document.createElement('p');
            empty.className = 'text-muted small text-center py-2 token-row';
            empty.textContent = 'No tokens yet.';
            tokenList.appendChild(empty);
            return;
        }

        var table = document.createElement('table');
        table.className = 'table table-sm align-middle mb-0 token-row';
        table.innerHTML =
            '<thead><tr>' +
            '<th class="small text-muted fw-normal">Name</th>' +
            '<th class="small text-muted fw-normal">Created</th>' +
            '<th class="small text-muted fw-normal">Expires</th>' +
            '<th></th>' +
            '</tr></thead>';
        var tbody = document.createElement('tbody');

        tokens.forEach(function (t) {
            var tr = document.createElement('tr');
            var expired = t.expired_at !== null;
            tr.innerHTML =
                '<td class="small' + (expired ? ' text-muted text-decoration-line-through' : '') + '">' +
                    escHtml(t.name) + (expired ? ' <span class="badge bg-secondary ms-1">revoked</span>' : '') +
                '</td>' +
                '<td class="small text-muted">' + escHtml(t.created_at || '—') + '</td>' +
                '<td class="small text-muted">' + (t.expires_at ? escHtml(t.expires_at) : '—') + '</td>' +
                '<td class="text-end">' +
                    (!expired
                        ? '<button class="btn btn-sm btn-outline-danger py-0 px-2 js-revoke-btn" ' +
                          'style="font-size:.75rem;" data-id="' + parseInt(t.id, 10) + '">Revoke</button>'
                        : '') +
                '</td>';
            tbody.appendChild(tr);
        });

        table.appendChild(tbody);
        tokenList.appendChild(table);

        // Bind revoke buttons
        tokenList.querySelectorAll('.js-revoke-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var id = parseInt(this.dataset.id, 10);
                if (!confirm('Revoke this token? This cannot be undone.')) return;
                revokeToken(id, this);
            });
        });
    }

    function revokeToken(id, btn) {
        btn.disabled = true;
        btn.textContent = '…';
        fetch('/api/tokens/' + id, {
            method: 'DELETE',
            credentials: 'same-origin',
        })
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                loadTokens();
            } else {
                btn.disabled = false;
                btn.textContent = 'Revoke';
                alert(data.error || 'Failed to revoke token.');
            }
        })
        .catch(function () {
            btn.disabled = false;
            btn.textContent = 'Revoke';
            alert('Request failed.');
        });
    }

    // ── Show/hide create form ─────────────────────────────────────────────
    newTokenBtn.addEventListener('click', function () {
        newTokenForm.style.removeProperty('display');
        tokenReveal.style.display = 'none';
        tokenName.focus();
    });

    cancelBtn.addEventListener('click', function () {
        newTokenForm.style.setProperty('display', 'none', 'important');
        tokenName.value    = '';
        tokenExpires.value = '';
        tokenError.style.display = 'none';
    });

    // ── Create token ──────────────────────────────────────────────────────
    createBtn.addEventListener('click', function () {
        var name    = tokenName.value.trim();
        var expires = tokenExpires.value.trim();

        if (!name) {
            tokenError.textContent = 'Token name is required.';
            tokenError.style.display = '';
            return;
        }

        tokenError.style.display = 'none';
        createBtn.disabled = true;
        createBtn.textContent = 'Creating…';

        var body = { name: name };
        if (expires) {
            // datetime-local gives "YYYY-MM-DDTHH:MM"; API wants "YYYY-MM-DD HH:MM:SS"
            body.expires_at = expires.replace('T', ' ') + ':00';
        }

        fetch('/api/tokens', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify(body),
        })
        .then(function (r) { return r.json().then(function (d) { return { status: r.status, data: d }; }); })
        .then(function (res) {
            createBtn.disabled = false;
            createBtn.textContent = 'Create';

            if (res.status !== 201) {
                tokenError.textContent = res.data.error || 'Failed to create token.';
                tokenError.style.display = '';
                return;
            }

            // Show the raw token once
            tokenValue.value = res.data.token;
            tokenReveal.style.display = '';
            newTokenForm.style.setProperty('display', 'none', 'important');
            tokenName.value    = '';
            tokenExpires.value = '';

            loadTokens();
        })
        .catch(function () {
            createBtn.disabled = false;
            createBtn.textContent = 'Create';
            tokenError.textContent = 'Request failed.';
            tokenError.style.display = '';
        });
    });

    // ── Copy token to clipboard ───────────────────────────────────────────
    tokenCopyBtn.addEventListener('click', function () {
        navigator.clipboard.writeText(tokenValue.value).then(function () {
            var orig = tokenCopyBtn.innerHTML;
            tokenCopyBtn.innerHTML = '<i class="bi bi-check2"></i>';
            setTimeout(function () { tokenCopyBtn.innerHTML = orig; }, 1500);
        });
    });

    // ── Helpers ───────────────────────────────────────────────────────────
    function escHtml(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // Kick off
    loadTokens();
}());
</script>
