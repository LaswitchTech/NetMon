<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Sign In &mdash; NetMon</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <style>
        body { background: #f0f2f5; }
    </style>
</head>
<body>
<div class="container py-5" style="max-width: 420px">

    <div class="text-center mb-4">
        <h1 class="h4 fw-bold mb-1">NetMon</h1>
        <p class="text-muted small mb-0">Sign in to continue</p>
    </div>

    <div class="card shadow-sm">
        <div class="card-body p-4">

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
                // Redirect to the application home on success
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
