<?php
/**
 * Setup wizard shell — rendered once at GET /setup.
 *
 * PHP variables available from SetupController::handleGetWizard():
 *   $csrfToken  (string)  — CSRF token for all AJAX POSTs
 *   $currentUrl (string)  — Current APP_URL from .env
 *   $appName    (string)  — Current APP_NAME from .env
 */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Setup &mdash; <?= htmlspecialchars($appName) ?></title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background: #f0f2f5; }

        /* Step indicator */
        .wizard-nav { position: relative; }
        .wizard-nav::before {
            content: '';
            position: absolute;
            top: 18px;
            left: calc(8.33% + 18px);
            right: calc(8.33% + 18px);
            height: 2px;
            background: #dee2e6;
            z-index: 0;
        }
        .step-item { display: flex; flex-direction: column; align-items: center; flex: 1; z-index: 1; }
        .step-dot {
            width: 36px; height: 36px; border-radius: 50%;
            display: flex; align-items: center; justify-content: center;
            font-size: .85rem; font-weight: 600;
            border: 2px solid #dee2e6;
            background: #fff; color: #6c757d;
            transition: background .2s, border-color .2s, color .2s;
        }
        .step-item.active   .step-dot { border-color: #0d6efd; color: #0d6efd; }
        .step-item.done     .step-dot { border-color: #0d6efd; background: #0d6efd; color: #fff; }
        .step-item.failed   .step-dot { border-color: #dc3545; background: #dc3545; color: #fff; }
        .step-label { font-size: .72rem; margin-top: 5px; color: #6c757d; }
        .step-item.active   .step-label { color: #0d6efd; font-weight: 600; }
        .step-item.done     .step-label { color: #0d6efd; }
        @media (max-width: 480px) { .step-label { display: none; } }

        /* Check list items */
        .check-row { display: flex; align-items: flex-start; gap: 10px; padding: 6px 0; border-bottom: 1px solid #f0f0f0; }
        .check-row:last-child { border-bottom: none; }
        .check-icon { width: 22px; flex-shrink: 0; text-align: center; margin-top: 1px; }
        .check-fix  { font-size: .8rem; color: #6c757d; margin-top: 2px; }

        /* Summary table */
        .summary-label { font-size: .8rem; font-weight: 600; color: #6c757d; text-transform: uppercase; letter-spacing: .04em; }

        /* Install progress */
        .install-row { display: flex; align-items: center; gap: 10px; padding: 8px 0; border-bottom: 1px solid #f0f0f0; }
        .install-row:last-child { border-bottom: none; }
        .install-icon { width: 22px; text-align: center; }
    </style>
</head>
<body>
<div class="container py-5" style="max-width: 680px">

    <!-- Header -->
    <div class="text-center mb-4">
        <h1 class="h3 fw-bold mb-1"><?= htmlspecialchars($appName) ?></h1>
        <p class="text-muted small mb-0">Installation Wizard</p>
    </div>

    <!-- Step indicator (6 steps; panels 7–8 are visual states of step 6) -->
    <div class="wizard-nav d-flex mb-4" id="wizard-nav">
        <div class="step-item active" id="nav-1">
            <div class="step-dot" id="dot-1">1</div>
            <span class="step-label">Welcome</span>
        </div>
        <div class="step-item" id="nav-2">
            <div class="step-dot" id="dot-2">2</div>
            <span class="step-label">Requirements</span>
        </div>
        <div class="step-item" id="nav-3">
            <div class="step-dot" id="dot-3">3</div>
            <span class="step-label">Database</span>
        </div>
        <div class="step-item" id="nav-4">
            <div class="step-dot" id="dot-4">4</div>
            <span class="step-label">Settings</span>
        </div>
        <div class="step-item" id="nav-5">
            <div class="step-dot" id="dot-5">5</div>
            <span class="step-label">Admin</span>
        </div>
        <div class="step-item" id="nav-6">
            <div class="step-dot" id="dot-6">6</div>
            <span class="step-label">Install</span>
        </div>
    </div>

    <!-- Wizard card -->
    <div class="card shadow-sm border-0">
        <div class="card-body p-4 p-md-5">

            <!-- ============================================================
                 Panel 1 — Welcome
                 ============================================================ -->
            <div class="wizard-panel" id="panel-1">
                <h2 class="h4 fw-semibold mb-3">Welcome to <?= htmlspecialchars($appName) ?> Setup</h2>
                <p class="text-muted mb-4">
                    This wizard will guide you through the installation process.
                    It should only take a few minutes.
                </p>
                <p class="fw-semibold mb-2 small text-uppercase text-muted" style="letter-spacing:.05em">What this wizard will do</p>
                <ul class="mb-4">
                    <li class="mb-1">Verify that your server meets the system requirements</li>
                    <li class="mb-1">Configure your database connection</li>
                    <li class="mb-1">Set application preferences</li>
                    <li class="mb-1">Create an administrator account</li>
                    <li class="mb-1">Run database migrations and finalize setup</li>
                </ul>
                <div class="d-flex justify-content-end">
                    <button class="btn btn-primary" onclick="Wizard.start()">
                        Start Installation <i class="bi bi-arrow-right ms-1"></i>
                    </button>
                </div>
            </div>

            <!-- ============================================================
                 Panel 2 — Requirements
                 ============================================================ -->
            <div class="wizard-panel d-none" id="panel-2">
                <h2 class="h4 fw-semibold mb-3">System Requirements</h2>
                <p class="text-muted small mb-3">
                    Checking that your server environment meets all prerequisites.
                </p>
                <div id="check-results">
                    <div class="text-center py-4 text-muted">
                        <div class="spinner-border spinner-border-sm mb-2" role="status"></div>
                        <p class="mb-0 small">Checking requirements&hellip;</p>
                    </div>
                </div>
                <div id="check-error" class="alert alert-danger mt-3 d-none"></div>
                <div class="d-flex justify-content-between mt-4">
                    <button class="btn btn-outline-secondary btn-sm" onclick="Wizard.goto(1)">
                        <i class="bi bi-arrow-left me-1"></i> Back
                    </button>
                    <div class="d-flex gap-2">
                        <button class="btn btn-outline-secondary btn-sm d-none" id="btn-check-retry"
                                onclick="Wizard.runCheck()">
                            <i class="bi bi-arrow-clockwise me-1"></i> Retry
                        </button>
                        <button class="btn btn-primary btn-sm" id="btn-check-next"
                                disabled onclick="Wizard.goto(3)">
                            Continue <i class="bi bi-arrow-right ms-1"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- ============================================================
                 Panel 3 — Database
                 ============================================================ -->
            <div class="wizard-panel d-none" id="panel-3">
                <h2 class="h4 fw-semibold mb-3">Database Configuration</h2>
                <p class="text-muted small mb-4">Select a database driver and verify the connection.</p>

                <!-- Driver selector -->
                <div class="mb-4">
                    <label class="form-label fw-semibold">Database driver</label>
                    <div class="d-flex gap-3">
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="db-driver"
                                   id="driver-sqlite" value="sqlite" checked
                                   onchange="Wizard.onDriverChange()">
                            <label class="form-check-label" for="driver-sqlite">SQLite</label>
                        </div>
                        <div class="form-check">
                            <input class="form-check-input" type="radio" name="db-driver"
                                   id="driver-mysql" value="mysql" disabled
                                   onchange="Wizard.onDriverChange()">
                            <label class="form-check-label text-muted" for="driver-mysql">
                                MySQL / MariaDB <span class="badge bg-secondary ms-1" style="font-size:.7rem">coming soon</span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- SQLite panel -->
                <div id="db-panel-sqlite" class="alert alert-light border mb-3">
                    <div class="d-flex gap-2 align-items-center mb-1">
                        <i class="bi bi-database text-primary"></i>
                        <strong>SQLite</strong>
                    </div>
                    <p class="mb-1 small text-muted">No server or credentials required.</p>
                    <p class="mb-0 small">Database file: <code>data/app.db</code></p>
                </div>

                <!-- MySQL panel (hidden, disabled) -->
                <div id="db-panel-mysql" class="d-none">
                    <p class="text-muted small">MySQL / MariaDB support is planned for a future release.</p>
                </div>

                <div id="db-test-result" class="mb-3 d-none"></div>

                <div class="d-flex justify-content-between mt-4">
                    <button class="btn btn-outline-secondary btn-sm" onclick="Wizard.goto(2)">
                        <i class="bi bi-arrow-left me-1"></i> Back
                    </button>
                    <div class="d-flex gap-2">
                        <button class="btn btn-outline-primary btn-sm" id="btn-db-test"
                                onclick="Wizard.testDb()">
                            <i class="bi bi-plug me-1"></i> Test Connection
                        </button>
                        <button class="btn btn-primary btn-sm" id="btn-db-next"
                                disabled onclick="Wizard.goto(4)">
                            Continue <i class="bi bi-arrow-right ms-1"></i>
                        </button>
                    </div>
                </div>
            </div>

            <!-- ============================================================
                 Panel 4 — App Settings
                 ============================================================ -->
            <div class="wizard-panel d-none" id="panel-4">
                <h2 class="h4 fw-semibold mb-3">Application Settings</h2>
                <p class="text-muted small mb-4">These settings are stored in <code>.env</code> and can be changed later.</p>

                <div class="mb-3">
                    <label for="app-name" class="form-label fw-semibold">Application name</label>
                    <input type="text" class="form-control" id="app-name"
                           value="<?= htmlspecialchars($appName) ?>" maxlength="100">
                    <div class="invalid-feedback" id="err-app-name"></div>
                </div>

                <div class="mb-3">
                    <label for="app-url" class="form-label fw-semibold">Base URL</label>
                    <input type="url" class="form-control" id="app-url"
                           value="<?= htmlspecialchars($currentUrl) ?>" placeholder="https://example.com">
                    <div class="form-text">Include scheme (https://). Trailing slash is optional.</div>
                    <div class="invalid-feedback" id="err-app-url"></div>
                </div>

                <div class="mb-3">
                    <label for="app-env" class="form-label fw-semibold">Environment</label>
                    <select class="form-select" id="app-env" onchange="Wizard.onEnvChange()">
                        <option value="production" selected>Production</option>
                        <option value="development">Development</option>
                    </select>
                    <div class="invalid-feedback" id="err-app-env"></div>
                </div>

                <div class="mb-3 d-none" id="debug-row">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" id="app-debug">
                        <label class="form-check-label" for="app-debug">
                            Enable debug mode
                            <span class="text-muted small">(shows detailed errors)</span>
                        </label>
                    </div>
                </div>

                <div id="config-error" class="alert alert-danger d-none mt-2"></div>

                <div class="d-flex justify-content-between mt-4">
                    <button class="btn btn-outline-secondary btn-sm" onclick="Wizard.goto(3)">
                        <i class="bi bi-arrow-left me-1"></i> Back
                    </button>
                    <button class="btn btn-primary btn-sm" onclick="Wizard.submitConfig()">
                        Continue <i class="bi bi-arrow-right ms-1"></i>
                    </button>
                </div>
            </div>

            <!-- ============================================================
                 Panel 5 — Admin Account
                 ============================================================ -->
            <div class="wizard-panel d-none" id="panel-5">
                <h2 class="h4 fw-semibold mb-1">Create Administrator Account</h2>
                <p class="text-muted small mb-4">This account will have full access to the application.</p>

                <div class="mb-3">
                    <label for="admin-name" class="form-label fw-semibold">Full name</label>
                    <input type="text" class="form-control" id="admin-name" maxlength="100"
                           autocomplete="name">
                    <div class="invalid-feedback" id="err-admin-name"></div>
                </div>

                <div class="mb-3">
                    <label for="admin-username" class="form-label fw-semibold">Username</label>
                    <input type="text" class="form-control" id="admin-username"
                           maxlength="50" pattern="[a-zA-Z0-9_]+"
                           autocomplete="username">
                    <div class="form-text">3–50 characters, letters, numbers, and underscores only.</div>
                    <div class="invalid-feedback" id="err-admin-username"></div>
                </div>

                <div class="mb-3">
                    <label for="admin-email" class="form-label fw-semibold">Email address</label>
                    <input type="email" class="form-control" id="admin-email"
                           autocomplete="email">
                    <div class="invalid-feedback" id="err-admin-email"></div>
                </div>

                <div class="mb-3">
                    <label for="admin-password" class="form-label fw-semibold">Password</label>
                    <input type="password" class="form-control" id="admin-password"
                           autocomplete="new-password" oninput="Wizard.onPasswordInput()">
                    <div class="form-text" id="password-hint">Minimum 8 characters.</div>
                    <div class="invalid-feedback" id="err-admin-password"></div>
                </div>

                <div class="mb-3">
                    <label for="admin-confirm" class="form-label fw-semibold">Confirm password</label>
                    <input type="password" class="form-control" id="admin-confirm"
                           autocomplete="new-password" oninput="Wizard.onConfirmInput()">
                    <div class="invalid-feedback" id="err-admin-confirm"></div>
                </div>

                <div id="admin-error" class="alert alert-danger d-none mt-2"></div>

                <div class="d-flex justify-content-between mt-4">
                    <button class="btn btn-outline-secondary btn-sm" onclick="Wizard.goto(4)">
                        <i class="bi bi-arrow-left me-1"></i> Back
                    </button>
                    <button class="btn btn-primary btn-sm" onclick="Wizard.submitAdmin()">
                        Continue <i class="bi bi-arrow-right ms-1"></i>
                    </button>
                </div>
            </div>

            <!-- ============================================================
                 Panel 6 — Summary
                 ============================================================ -->
            <div class="wizard-panel d-none" id="panel-6">
                <h2 class="h4 fw-semibold mb-1">Ready to Install</h2>
                <p class="text-muted small mb-4">Review your settings before installation begins.</p>

                <!-- Database summary -->
                <div class="mb-3">
                    <p class="summary-label mb-2">Database</p>
                    <div class="ps-1">
                        <div class="text-muted small"><span class="fw-semibold text-body">Driver:</span> <span id="sum-driver">SQLite</span></div>
                        <div class="text-muted small"><span class="fw-semibold text-body">File:</span> <code>data/app.db</code></div>
                    </div>
                </div>

                <!-- App settings summary -->
                <div class="mb-3">
                    <p class="summary-label mb-2">Application</p>
                    <div class="ps-1">
                        <div class="text-muted small"><span class="fw-semibold text-body">Name:</span> <span id="sum-app-name"></span></div>
                        <div class="text-muted small"><span class="fw-semibold text-body">URL:</span> <span id="sum-app-url"></span></div>
                        <div class="text-muted small"><span class="fw-semibold text-body">Environment:</span> <span id="sum-app-env"></span></div>
                    </div>
                </div>

                <!-- Admin summary -->
                <div class="mb-3">
                    <p class="summary-label mb-2">Administrator</p>
                    <div class="ps-1">
                        <div class="text-muted small"><span class="fw-semibold text-body">Name:</span> <span id="sum-admin-name"></span></div>
                        <div class="text-muted small"><span class="fw-semibold text-body">Username:</span> <span id="sum-admin-username"></span></div>
                        <div class="text-muted small"><span class="fw-semibold text-body">Email:</span> <span id="sum-admin-email"></span></div>
                    </div>
                </div>

                <!-- Install steps -->
                <div class="mb-4">
                    <p class="summary-label mb-2">Steps that will run</p>
                    <ol class="small text-muted mb-0 ps-4">
                        <li>Write <code>.env</code> and <code>config/local.php</code></li>
                        <li>Open database connection</li>
                        <li>Run database migrations</li>
                        <li>Run bootstrap seeds (permissions, admin group)</li>
                        <li>Create administrator account</li>
                        <li>Write installation lock</li>
                    </ol>
                </div>

                <div class="d-flex justify-content-between mt-4">
                    <button class="btn btn-outline-secondary btn-sm" id="btn-summary-back"
                            onclick="Wizard.goto(5)">
                        <i class="bi bi-arrow-left me-1"></i> Back
                    </button>
                    <button class="btn btn-success" id="btn-install-now"
                            onclick="Wizard.runInstall()">
                        <i class="bi bi-rocket-takeoff me-1"></i> Install Now
                    </button>
                </div>
            </div>

            <!-- ============================================================
                 Panel 7 — Installing
                 ============================================================ -->
            <div class="wizard-panel d-none" id="panel-7">
                <h2 class="h4 fw-semibold mb-3">Installing&hellip;</h2>
                <p class="text-muted small mb-4">Please wait while the installation completes.</p>

                <div id="install-steps">
                    <div class="install-row" id="istep-config">
                        <div class="install-icon text-muted"><i class="bi bi-circle"></i></div>
                        <span>Writing configuration files</span>
                    </div>
                    <div class="install-row" id="istep-database">
                        <div class="install-icon text-muted"><i class="bi bi-circle"></i></div>
                        <span>Connecting to database</span>
                    </div>
                    <div class="install-row" id="istep-migrations">
                        <div class="install-icon text-muted"><i class="bi bi-circle"></i></div>
                        <span>Running database migrations</span>
                    </div>
                    <div class="install-row" id="istep-seeds">
                        <div class="install-icon text-muted"><i class="bi bi-circle"></i></div>
                        <span>Running bootstrap seeds</span>
                    </div>
                    <div class="install-row" id="istep-admin">
                        <div class="install-icon text-muted"><i class="bi bi-circle"></i></div>
                        <span>Creating administrator account</span>
                    </div>
                    <div class="install-row" id="istep-finalize">
                        <div class="install-icon text-muted"><i class="bi bi-circle"></i></div>
                        <span>Writing installation lock</span>
                    </div>
                </div>

                <div id="install-error" class="alert alert-danger mt-4 d-none">
                    <p class="fw-semibold mb-1">Installation failed</p>
                    <p class="mb-2 small" id="install-error-msg"></p>
                    <button class="btn btn-outline-danger btn-sm" onclick="Wizard.retryInstall()">
                        <i class="bi bi-arrow-clockwise me-1"></i> Retry
                    </button>
                </div>
            </div>

            <!-- ============================================================
                 Panel 8 — Complete
                 ============================================================ -->
            <div class="wizard-panel d-none" id="panel-8">
                <div class="text-center py-3">
                    <i class="bi bi-check-circle-fill text-success" style="font-size: 3.5rem"></i>
                    <h2 class="h4 fw-semibold mt-3 mb-2">Installation Complete</h2>
                    <p class="text-muted mb-1">
                        <?= htmlspecialchars($appName) ?> has been installed successfully.
                    </p>
                    <p class="text-muted small mb-4">
                        Log in with username: <strong id="done-username"></strong>
                    </p>
                    <a href="/auth/login" class="btn btn-primary">
                        <i class="bi bi-box-arrow-in-right me-1"></i> Go to Login
                    </a>
                </div>
            </div>

        </div><!-- /.card-body -->
    </div><!-- /.card -->

</div><!-- /.container -->

<script>
(function () {
    // PHP-injected values
    const CSRF_TOKEN = <?= json_encode($csrfToken) ?>;
    const APP_NAME   = <?= json_encode($appName) ?>;

    // In-memory password — never stored in session or DOM
    let _password = '';

    // Collected wizard state (mirrors session on server)
    const state = { db: null, config: null, admin: null };

    // -------------------------------------------------------------------------
    // Navigation
    // -------------------------------------------------------------------------

    // Nav step → panel mapping (nav steps 1–6; panels 1–8)
    const NAV_STEP = { 1:1, 2:2, 3:3, 4:4, 5:5, 6:6, 7:6, 8:6 };

    function show(panel) {
        document.querySelectorAll('.wizard-panel').forEach(p => p.classList.add('d-none'));
        const el = document.getElementById('panel-' + panel);
        if (el) el.classList.remove('d-none');
        updateNav(NAV_STEP[panel] || panel);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }

    function updateNav(activeStep) {
        for (let i = 1; i <= 6; i++) {
            const item = document.getElementById('nav-' + i);
            const dot  = document.getElementById('dot-' + i);
            item.classList.remove('active', 'done', 'failed');
            if (i < activeStep) {
                item.classList.add('done');
                dot.innerHTML = '<i class="bi bi-check"></i>';
            } else if (i === activeStep) {
                item.classList.add('active');
                dot.textContent = i;
            } else {
                dot.textContent = i;
            }
        }
    }

    // -------------------------------------------------------------------------
    // HTTP helper
    // -------------------------------------------------------------------------

    async function post(url, data) {
        const res = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': CSRF_TOKEN,
            },
            body: JSON.stringify(data),
        });

        if (res.status === 403) {
            // Session expired or CSRF mismatch — restart wizard
            alert('Your session has expired. The page will reload.');
            window.location.href = '/setup';
            throw new Error('session_expired');
        }

        return res.json();
    }

    // -------------------------------------------------------------------------
    // Field error helpers
    // -------------------------------------------------------------------------

    function setError(fieldId, msg) {
        const el = document.getElementById(fieldId);
        if (!el) return;
        el.textContent = msg;
        el.classList.remove('d-none');
        // Mark the input invalid
        const inputId = fieldId.replace(/^err-/, '').replace(/-/g, '-');
        const input = document.querySelector('[id="' + inputId.replace('err-', '') + '"]')
                   || document.getElementById(inputId.replace('err-', 'admin-'));
        if (input) input.classList.add('is-invalid');
    }

    function clearErrors(prefix) {
        document.querySelectorAll('[id^="err-' + (prefix || '') + '"]').forEach(el => {
            el.textContent = '';
            el.classList.add('d-none');
        });
        document.querySelectorAll('.is-invalid').forEach(el => el.classList.remove('is-invalid'));
    }

    function showErrors(errors) {
        for (const [field, msg] of Object.entries(errors)) {
            const errId = 'err-' + field.replace(/_/g, '-');
            const elById = document.getElementById(errId);
            if (elById) {
                elById.textContent = msg;
                elById.classList.remove('d-none');
                const inputName = field.replace(/_/g, '-');
                const input = document.getElementById(inputName)
                           || document.getElementById('admin-' + inputName)
                           || document.getElementById('app-' + inputName);
                if (input) input.classList.add('is-invalid');
            }
        }
    }

    // -------------------------------------------------------------------------
    // Panel 1 — Welcome
    // -------------------------------------------------------------------------

    function start() {
        show(2);
        runCheck();
    }

    // -------------------------------------------------------------------------
    // Panel 2 — Requirements
    // -------------------------------------------------------------------------

    async function runCheck() {
        const resultsEl  = document.getElementById('check-results');
        const retryBtn   = document.getElementById('btn-check-retry');
        const nextBtn    = document.getElementById('btn-check-next');
        const errorEl    = document.getElementById('check-error');

        resultsEl.innerHTML =
            '<div class="text-center py-4 text-muted">'
            + '<div class="spinner-border spinner-border-sm mb-2" role="status"></div>'
            + '<p class="mb-0 small">Checking requirements\u2026</p></div>';
        retryBtn.classList.add('d-none');
        nextBtn.disabled = true;
        errorEl.classList.add('d-none');

        let data;
        try {
            data = await post('/setup/check', {});
        } catch (e) {
            if (e.message !== 'session_expired') {
                errorEl.textContent = 'Unexpected error. Please try again.';
                errorEl.classList.remove('d-none');
                retryBtn.classList.remove('d-none');
            }
            return;
        }

        // Render check results
        const html = renderChecks(data);
        resultsEl.innerHTML = html;

        if (data.ok) {
            nextBtn.disabled = false;
        } else {
            retryBtn.classList.remove('d-none');
        }
    }

    function renderChecks(data) {
        let html = '';

        const envChecks = (data.env && data.env.checks) ? data.env.checks : [];
        const dirPaths  = (data.dir && data.dir.paths)  ? data.dir.paths  : [];

        if (envChecks.length > 0) {
            html += '<p class="small fw-semibold text-muted text-uppercase mb-1" style="letter-spacing:.04em">Environment</p>';
            for (const c of envChecks) {
                html += renderCheckRow(c.status === 'pass', c.status === 'optional', c.label, c.detail, c.fix);
            }
        }

        if (dirPaths.length > 0) {
            html += '<p class="small fw-semibold text-muted text-uppercase mb-1 mt-3" style="letter-spacing:.04em">Directories</p>';
            for (const p of dirPaths) {
                const ok = p.status === 'ok';
                html += renderCheckRow(ok, false, p.path, '', p.fix);
            }
        }

        return html || '<p class="text-muted small">No checks returned.</p>';
    }

    function renderCheckRow(pass, optional, label, detail, fix) {
        let icon, cls;
        if (pass) {
            icon = '<i class="bi bi-check-circle-fill text-success"></i>';
            cls  = '';
        } else if (optional) {
            icon = '<i class="bi bi-dash-circle-fill text-warning"></i>';
            cls  = '';
        } else {
            icon = '<i class="bi bi-x-circle-fill text-danger"></i>';
            cls  = '';
        }

        const detailHtml = detail ? ' <span class="text-muted small">(' + esc(detail) + ')</span>' : '';
        const fixHtml    = (!pass && !optional && fix)
            ? '<div class="check-fix"><i class="bi bi-wrench me-1"></i>' + esc(fix) + '</div>'
            : '';

        return '<div class="check-row">'
            + '<div class="check-icon">' + icon + '</div>'
            + '<div><span class="small">' + esc(label) + '</span>' + detailHtml + fixHtml + '</div>'
            + '</div>';
    }

    // -------------------------------------------------------------------------
    // Panel 3 — Database
    // -------------------------------------------------------------------------

    function onDriverChange() {
        const driver  = document.querySelector('input[name="db-driver"]:checked').value;
        const sqlite  = document.getElementById('db-panel-sqlite');
        const mysql   = document.getElementById('db-panel-mysql');
        const testRes = document.getElementById('db-test-result');
        const nextBtn = document.getElementById('btn-db-next');

        sqlite.classList.toggle('d-none', driver !== 'sqlite');
        mysql.classList.toggle('d-none',  driver !== 'mysql');

        // Reset test state when driver changes
        testRes.classList.add('d-none');
        nextBtn.disabled = true;
        state.db = null;
    }

    async function testDb() {
        const driver  = document.querySelector('input[name="db-driver"]:checked').value;
        const testBtn = document.getElementById('btn-db-test');
        const testRes = document.getElementById('db-test-result');
        const nextBtn = document.getElementById('btn-db-next');

        testBtn.disabled = true;
        testBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1" role="status"></span> Testing\u2026';
        testRes.classList.add('d-none');
        nextBtn.disabled = true;

        let data;
        try {
            data = await post('/setup/db', { driver, params: {} });
        } catch (e) {
            testBtn.disabled = false;
            testBtn.innerHTML = '<i class="bi bi-plug me-1"></i> Test Connection';
            return;
        }

        testBtn.disabled = false;
        testBtn.innerHTML = '<i class="bi bi-plug me-1"></i> Test Connection';
        testRes.classList.remove('d-none');

        if (data.ok) {
            testRes.className = 'alert alert-success mb-3';
            testRes.innerHTML = '<i class="bi bi-check-circle me-1"></i> Connection successful.';
            nextBtn.disabled  = false;
            state.db = { driver, params: {} };
        } else {
            testRes.className = 'alert alert-danger mb-3';
            testRes.innerHTML = '<i class="bi bi-exclamation-triangle me-1"></i> '
                + esc(data.error || 'Connection failed.');
        }
    }

    // -------------------------------------------------------------------------
    // Panel 4 — App Settings
    // -------------------------------------------------------------------------

    function onEnvChange() {
        const env      = document.getElementById('app-env').value;
        const debugRow = document.getElementById('debug-row');
        if (env === 'production') {
            debugRow.classList.add('d-none');
            document.getElementById('app-debug').checked = false;
        } else {
            debugRow.classList.remove('d-none');
        }
    }

    async function submitConfig() {
        clearErrors('app');
        const errorEl = document.getElementById('config-error');
        errorEl.classList.add('d-none');

        const payload = {
            app_name:  document.getElementById('app-name').value.trim(),
            app_url:   document.getElementById('app-url').value.trim(),
            app_env:   document.getElementById('app-env').value,
            app_debug: document.getElementById('app-debug').checked,
        };

        let data;
        try {
            data = await post('/setup/config', payload);
        } catch (e) { return; }

        if (!data.ok) {
            if (data.errors) {
                showErrors(data.errors);
            } else {
                errorEl.textContent = data.error || 'Validation failed.';
                errorEl.classList.remove('d-none');
            }
            return;
        }

        state.config = payload;
        show(5);
    }

    // -------------------------------------------------------------------------
    // Panel 5 — Admin Account
    // -------------------------------------------------------------------------

    function onPasswordInput() {
        const pw   = document.getElementById('admin-password').value;
        const hint = document.getElementById('password-hint');
        hint.textContent = pw.length < 8
            ? 'Minimum 8 characters (' + pw.length + '/' + 8 + ')'
            : 'Looks good.';
    }

    function onConfirmInput() {
        const pw      = document.getElementById('admin-password').value;
        const confirm = document.getElementById('admin-confirm').value;
        const errEl   = document.getElementById('err-admin-confirm');
        if (confirm && confirm !== pw) {
            errEl.textContent = 'Passwords do not match.';
            errEl.classList.remove('d-none');
            document.getElementById('admin-confirm').classList.add('is-invalid');
        } else {
            errEl.textContent = '';
            errEl.classList.add('d-none');
            document.getElementById('admin-confirm').classList.remove('is-invalid');
        }
    }

    async function submitAdmin() {
        clearErrors('admin');
        const errorEl = document.getElementById('admin-error');
        errorEl.classList.add('d-none');

        const pw      = document.getElementById('admin-password').value;
        const confirm = document.getElementById('admin-confirm').value;

        const payload = {
            display_name:     document.getElementById('admin-name').value.trim(),
            username:         document.getElementById('admin-username').value.trim(),
            email:            document.getElementById('admin-email').value.trim(),
            password:         pw,
            password_confirm: confirm,
        };

        let data;
        try {
            data = await post('/setup/admin', payload);
        } catch (e) { return; }

        if (!data.ok) {
            if (data.errors) {
                showErrors(data.errors);
            } else {
                errorEl.textContent = data.error || 'Validation failed.';
                errorEl.classList.remove('d-none');
            }
            return;
        }

        // Store admin info (no password) and keep password in memory only
        state.admin = {
            display_name: payload.display_name,
            username:     payload.username,
            email:        payload.email,
        };
        _password = pw;

        populateSummary();
        show(6);
    }

    // -------------------------------------------------------------------------
    // Panel 6 — Summary
    // -------------------------------------------------------------------------

    function populateSummary() {
        const set = (id, val) => {
            const el = document.getElementById(id);
            if (el) el.textContent = val || '—';
        };

        const driver = state.db ? state.db.driver : 'sqlite';
        set('sum-driver',       driver.charAt(0).toUpperCase() + driver.slice(1));
        set('sum-app-name',     state.config ? state.config.app_name : '');
        set('sum-app-url',      state.config ? state.config.app_url  : '');
        set('sum-app-env',      state.config ? state.config.app_env  : '');
        set('sum-admin-name',   state.admin  ? state.admin.display_name : '');
        set('sum-admin-username', state.admin ? state.admin.username : '');
        set('sum-admin-email',  state.admin  ? state.admin.email     : '');
    }

    // -------------------------------------------------------------------------
    // Panel 7 — Installing
    // -------------------------------------------------------------------------

    async function runInstall() {
        // Lock the summary buttons
        document.getElementById('btn-install-now').disabled  = true;
        document.getElementById('btn-summary-back').disabled = true;

        show(7);

        // Reset install step icons
        const steps = ['config', 'database', 'migrations', 'seeds', 'admin', 'finalize'];
        for (const s of steps) {
            setInstallIcon(s, 'pending');
        }

        // Animate "in-progress" state on first step
        setInstallIcon('config', 'running');

        let data;
        try {
            data = await post('/setup/install', { password: _password });
        } catch (e) {
            if (e.message !== 'session_expired') {
                showInstallError('all', 'Unexpected network error. Please retry.');
            }
            return;
        }

        if (data.ok) {
            // Animate all steps to complete
            for (const s of steps) {
                setInstallIcon(s, 'done');
            }
            _password = '';  // clear from memory

            // Show success panel
            const usernameEl = document.getElementById('done-username');
            if (usernameEl) usernameEl.textContent = data.username || '';

            setTimeout(() => show(8), 600);
        } else {
            // Mark steps up to the failed phase
            const phaseOrder = ['config', 'database', 'migrations', 'seeds', 'admin', 'finalize'];
            const failedPhase = data.phase || 'config';
            const failIdx = phaseOrder.indexOf(failedPhase);

            for (let i = 0; i < phaseOrder.length; i++) {
                if (i < failIdx)       setInstallIcon(phaseOrder[i], 'done');
                else if (i === failIdx) setInstallIcon(phaseOrder[i], 'failed');
                else                   setInstallIcon(phaseOrder[i], 'pending');
            }

            let errMsg = data.error || 'An unexpected error occurred.';
            if (data.phase === 'admin' && data.errors) {
                const msgs = Object.values(data.errors).join(' ');
                errMsg = msgs || errMsg;
            }
            showInstallError(failedPhase, errMsg);
        }
    }

    function retryInstall() {
        // For admin-phase failures, go back to admin form so user can correct + re-enter password
        document.getElementById('install-error').classList.add('d-none');
        document.getElementById('btn-install-now').disabled  = false;
        document.getElementById('btn-summary-back').disabled = false;

        const lastPhase = document.getElementById('install-error').dataset.phase || '';
        if (lastPhase === 'admin') {
            show(5);
        } else {
            show(6);
        }
    }

    function setInstallIcon(step, state) {
        const el = document.querySelector('#istep-' + step + ' .install-icon');
        if (!el) return;
        const icons = {
            pending: '<i class="bi bi-circle text-muted"></i>',
            running: '<div class="spinner-border spinner-border-sm text-primary" role="status"></div>',
            done:    '<i class="bi bi-check-circle-fill text-success"></i>',
            failed:  '<i class="bi bi-x-circle-fill text-danger"></i>',
        };
        el.innerHTML = icons[state] || icons.pending;
    }

    function showInstallError(phase, msg) {
        const el = document.getElementById('install-error');
        document.getElementById('install-error-msg').textContent = msg;
        el.dataset.phase = phase;
        el.classList.remove('d-none');
    }

    // -------------------------------------------------------------------------
    // Utility
    // -------------------------------------------------------------------------

    function esc(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;');
    }

    // -------------------------------------------------------------------------
    // Public API (called from inline onclick handlers)
    // -------------------------------------------------------------------------

    window.Wizard = {
        goto:          show,
        start:         start,
        runCheck:      runCheck,
        onDriverChange:onDriverChange,
        testDb:        testDb,
        onEnvChange:   onEnvChange,
        submitConfig:  submitConfig,
        onPasswordInput: onPasswordInput,
        onConfirmInput: onConfirmInput,
        submitAdmin:   submitAdmin,
        runInstall:    runInstall,
        retryInstall:  retryInstall,
    };

    // Initial state
    updateNav(1);

})();
</script>
</body>
</html>
