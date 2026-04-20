<!DOCTYPE html>
<html lang="en" data-bs-theme="dark">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign In &mdash; NetMon</title>

    <!-- Bootstrap 5.3.3 -->
    <link rel="stylesheet" href="/assets/vendor/bootstrap/5.3.3/css/bootstrap.min.css">
    <!-- Bootstrap Icons 1.11.3 -->
    <link rel="stylesheet" href="/assets/vendor/bootstrap-icons/1.11.3/bootstrap-icons.min.css">
    <!-- App theme -->
    <link rel="stylesheet" href="/assets/css/app.css">
</head>
<body>

<div class="d-flex align-items-center justify-content-center min-vh-100 p-3">

    <div class="auth-card">

        <div class="auth-card-logo">
            <span class="auth-card-icon">
                <i class="bi bi-activity"></i>
            </span>
        </div>

        <h1 class="auth-card-title">NetMon</h1>
        <p class="auth-card-subtitle">Sign in to continue</p>

        <div id="login-error" class="alert alert-danger d-none" role="alert"></div>

        <form id="login-form" novalidate>
            <div class="mb-3">
                <label for="identity" class="form-label">Username or Email</label>
                <input
                    type="text"
                    id="identity"
                    name="identity"
                    class="form-control"
                    autocomplete="username"
                    autofocus
                    required
                >
            </div>
            <div class="mb-4">
                <label for="password" class="form-label">Password</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    class="form-control"
                    autocomplete="current-password"
                    required
                >
            </div>
            <button type="submit" class="btn btn-primary w-100" id="submit-btn">
                <span id="submit-label">Sign In</span>
                <span id="submit-spinner" class="spinner-border spinner-border-sm ms-1 d-none" role="status"></span>
            </button>
        </form>

    </div>

</div>

<script>
(function () {
    const form     = document.getElementById('login-form');
    const errorBox = document.getElementById('login-error');
    const btn      = document.getElementById('submit-btn');
    const label    = document.getElementById('submit-label');
    const spinner  = document.getElementById('submit-spinner');

    function setLoading(on) {
        btn.disabled = on;
        spinner.classList.toggle('d-none', !on);
        label.textContent = on ? 'Signing in…' : 'Sign In';
    }

    function showError(msg) {
        errorBox.textContent = msg;
        errorBox.classList.remove('d-none');
    }

    function hideError() {
        errorBox.classList.add('d-none');
    }

    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        hideError();

        const identity = document.getElementById('identity').value.trim();
        const password = document.getElementById('password').value;

        if (!identity || !password) {
            showError('Please enter your username/email and password.');
            return;
        }

        setLoading(true);

        try {
            const res = await fetch('/auth/login', {
                method:  'POST',
                headers: { 'Content-Type': 'application/json' },
                body:    JSON.stringify({ identity, password }),
            });

            const data = await res.json();

            if (res.ok && data.user) {
                window.location.href = '/';
            } else {
                showError(data.error ?? 'Login failed. Please try again.');
            }
        } catch (err) {
            showError('Network error. Please try again.');
        } finally {
            setLoading(false);
        }
    });
})();
</script>
</body>
</html>
