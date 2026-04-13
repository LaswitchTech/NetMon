<?php

namespace App\Controllers;

use App\Auth\TokenService;
use App\Core\Controller;

/**
 * Manages API tokens for the authenticated user.
 * All routes require SessionAuth middleware.
 */
class TokenController extends Controller
{
    // -------------------------------------------------------------------------
    // GET /api/tokens
    // List all tokens for the current user (hashes never exposed).
    // -------------------------------------------------------------------------

    public function index(array $params = []): void
    {
        $principal = $this->container->get('principal');
        $userId    = $principal['user']['id'];

        /** @var TokenService $tokens */
        $tokens = $this->container->get('tokens');

        $this->json(['tokens' => $tokens->listForUser($userId)]);
    }

    // -------------------------------------------------------------------------
    // POST /api/tokens
    // Body: { "name": "label", "expires_at": "2025-12-31 00:00:00" (optional) }
    // Returns the raw token ONCE — it cannot be retrieved again.
    // -------------------------------------------------------------------------

    public function create(array $params = []): void
    {
        $name      = trim((string) $this->input('name', ''));
        $expiresAt = $this->input('expires_at');

        if ($name === '') {
            $this->json(['error' => 'Token name is required'], 400);
            return;
        }

        if ($expiresAt !== null) {
            $expiresAt = trim((string) $expiresAt);

            if (strtotime($expiresAt) === false || strtotime($expiresAt) <= time()) {
                $this->json(['error' => 'expires_at must be a future datetime (Y-m-d H:i:s)'], 400);
                return;
            }
        }

        $principal = $this->container->get('principal');
        $userId    = $principal['user']['id'];

        /** @var TokenService $tokens */
        $tokens = $this->container->get('tokens');
        $result = $tokens->generate($userId, $name, $expiresAt ?: null);

        $this->json([
            'token'  => $result['raw'],    // shown ONCE — instruct the client to store it
            'record' => $result['record'],
        ], 201);
    }

    // -------------------------------------------------------------------------
    // DELETE /api/tokens/{id}
    // Revoke one of the current user's tokens.
    // -------------------------------------------------------------------------

    public function revoke(array $params = []): void
    {
        $id = (int) ($params['id'] ?? 0);

        if ($id <= 0) {
            $this->json(['error' => 'Invalid token ID'], 400);
            return;
        }

        $principal = $this->container->get('principal');
        $userId    = $principal['user']['id'];

        /** @var TokenService $tokens */
        $tokens  = $this->container->get('tokens');
        $success = $tokens->revoke($id, $userId);

        if (!$success) {
            $this->json(['error' => 'Token not found or already revoked'], 404);
            return;
        }

        $this->json(['success' => true]);
    }
}
