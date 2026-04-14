<?php

namespace App\Controllers;

use App\Auth\AuthService;
use App\Core\Controller;

class AuthController extends Controller
{
    // -------------------------------------------------------------------------
    // GET /auth/login — render the login page
    // -------------------------------------------------------------------------

    public function loginForm(array $params = []): void
    {
        /** @var AuthService $auth */
        $auth = $this->container->get('auth');

        // Already authenticated — send to home
        if ($auth->check()) {
            header('Location: /', true, 302);
            exit;
        }

        http_response_code(200);
        header('Content-Type: text/html; charset=utf-8');
        require __DIR__ . '/../Views/auth/login.php';
    }

    // -------------------------------------------------------------------------
    // POST /auth/login
    // Body: { "identity": "username or email", "password": "..." }
    // -------------------------------------------------------------------------

    public function login(array $params = []): void
    {
        $identity = trim((string) $this->input('identity', ''));
        $password = (string) $this->input('password', '');

        if ($identity === '' || $password === '') {
            $this->json(['error' => 'Identity and password are required'], 400);
            return;
        }

        /** @var AuthService $auth */
        $auth = $this->container->get('auth');

        $user = $auth->login([
            'identity' => $identity,
            'password' => $password,
        ]);

        if ($user === null) {
            // Deliberately vague — do not reveal whether the identity exists
            $this->json(['error' => 'Invalid credentials'], 401);
            return;
        }

        $this->json(['user' => $user]);
    }

    // -------------------------------------------------------------------------
    // POST /auth/logout
    // -------------------------------------------------------------------------

    public function logout(array $params = []): void
    {
        /** @var AuthService $auth */
        $auth = $this->container->get('auth');
        $auth->logout();

        $this->json(['success' => true]);
    }

    // -------------------------------------------------------------------------
    // GET /auth/me
    // -------------------------------------------------------------------------

    public function me(array $params = []): void
    {
        /** @var AuthService $auth */
        $auth = $this->container->get('auth');
        $user = $auth->user();

        if ($user === null) {
            $this->json(['error' => 'Not authenticated'], 401);
            return;
        }

        $this->json(['user' => $user]);
    }
}
