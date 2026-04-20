<?php if ($flash): ?>
<div class="alert alert-<?= $flash['type'] === 'success' ? 'success' : 'danger' ?> alert-dismissible mb-4" role="alert">
    <?= htmlspecialchars($flash['message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
</div>
<?php endif; ?>

<form method="POST" action="/admin/settings" novalidate>

<div class="row g-4">

    <!-- ── Left column ───────────────────────────────────────────────── -->
    <div class="col-lg-6">

        <!-- Application -->
        <div class="card mb-4">
            <div class="card-header">
                <span class="fw-semibold small">Application</span>
            </div>
            <div class="card-body">

                <div class="mb-3">
                    <label for="app-name" class="form-label">
                        Application Name <span class="text-danger">*</span>
                    </label>
                    <input type="text"
                           id="app-name"
                           name="app_name"
                           class="form-control <?= isset($errors['app_name']) ? 'is-invalid' : '' ?>"
                           value="<?= htmlspecialchars($settings['app_name'] ?? '') ?>"
                           maxlength="100"
                           required>
                    <?php if (isset($errors['app_name'])): ?>
                        <div class="invalid-feedback"><?= htmlspecialchars($errors['app_name']) ?></div>
                    <?php else: ?>
                        <div class="form-text">Displayed in the browser title bar and sidebar header.</div>
                    <?php endif; ?>
                </div>

                <div class="mb-0">
                    <label for="app-url" class="form-label">
                        Application URL <span class="text-danger">*</span>
                    </label>
                    <input type="url"
                           id="app-url"
                           name="app_url"
                           class="form-control <?= isset($errors['app_url']) ? 'is-invalid' : '' ?>"
                           value="<?= htmlspecialchars($settings['app_url'] ?? '') ?>"
                           maxlength="255"
                           placeholder="https://netmon.example.com"
                           required>
                    <?php if (isset($errors['app_url'])): ?>
                        <div class="invalid-feedback"><?= htmlspecialchars($errors['app_url']) ?></div>
                    <?php else: ?>
                        <div class="form-text">
                            Public-facing URL. Used in email links and external references.
                            No trailing slash.
                        </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>

        <!-- Monitoring -->
        <div class="card">
            <div class="card-header">
                <span class="fw-semibold small">Monitoring</span>
            </div>
            <div class="card-body">

                <div class="mb-0">
                    <label for="check-interval" class="form-label">
                        Default Check Interval <span class="text-danger">*</span>
                    </label>
                    <div class="input-group <?= isset($errors['check_interval']) ? 'has-validation' : '' ?>">
                        <input type="number"
                               id="check-interval"
                               name="check_interval"
                               class="form-control <?= isset($errors['check_interval']) ? 'is-invalid' : '' ?>"
                               value="<?= (int) ($settings['check_interval'] ?? 60) ?>"
                               min="5"
                               max="3600"
                               step="1"
                               required>
                        <span class="input-group-text">seconds</span>
                        <?php if (isset($errors['check_interval'])): ?>
                            <div class="invalid-feedback"><?= htmlspecialchars($errors['check_interval']) ?></div>
                        <?php endif; ?>
                    </div>
                    <?php if (!isset($errors['check_interval'])): ?>
                    <div class="form-text">
                        How often the monitoring runner checks each device and service.
                        Range: 5 – 3600 s. The actual run frequency is controlled by
                        your cron schedule.
                    </div>
                    <?php endif; ?>
                </div>

            </div>
        </div>

    </div>

    <!-- ── Right column ──────────────────────────────────────────────── -->
    <div class="col-lg-6">

        <!-- Notifications -->
        <div class="card">
            <div class="card-header">
                <span class="fw-semibold small">Notifications</span>
            </div>
            <div class="card-body">

                <div class="mb-3">
                    <div class="form-check form-switch">
                        <input class="form-check-input"
                               type="checkbox"
                               id="email-enabled"
                               name="email_enabled"
                               value="1"
                               <?= !empty($settings['email_enabled']) ? 'checked' : '' ?>>
                        <label class="form-check-label" for="email-enabled">
                            Enable email notifications
                        </label>
                    </div>
                    <div class="form-text mt-1">
                        Master switch for email delivery. When disabled, no emails are
                        sent regardless of per-user preferences or alert rules.
                        SMTP credentials are configured in
                        <code>config/local.php</code> (not managed here).
                    </div>
                </div>

            </div>
        </div>

    </div>

</div><!-- /.row -->

<div class="mt-4">
    <button type="submit" class="btn btn-primary">
        <i class="bi bi-check-lg me-1"></i>Save Settings
    </button>
</div>

</form>
