# NetMon HTTP API Reference

## Overview

All endpoints return **JSON**. There is no server-rendered HTML at this stage.

The API is consumed by the AJAX-driven frontend (not yet built) and by headless clients using API tokens.

---

## Conventions

### Request format

Endpoints that accept a body expect `Content-Type: application/json`.

```json
{ "key": "value" }
```

### Success response

HTTP 2xx with a JSON object. Structure varies per endpoint (documented below).

### Error response

All errors return a consistent envelope:

```json
{
  "error": "Human-readable message"
}
```

In debug mode (`config/app.php debug = true`), unhandled exceptions also include `exception`, `file`, `line`, and `trace` fields. These are suppressed in production.

### HTTP status codes used

| Code | Meaning |
|---|---|
| 200 | OK |
| 201 | Created |
| 400 | Bad Request — missing or invalid input |
| 401 | Unauthorized — not authenticated |
| 403 | Forbidden — authenticated but lacks permission |
| 404 | Not Found — no route matched, or resource not found |
| 500 | Internal Server Error |

---

## Authentication

Two authentication methods are supported. For full details see → [auth.md](auth.md).

**Session auth** — used by browser clients:
- Log in via `POST /auth/login` to receive a session cookie.
- The cookie is sent automatically on subsequent requests.

**Token auth** — used by API clients:
- Generate a token via `POST /api/tokens` (requires a session first).
- Pass the raw token in the `Authorization` header:
  ```
  Authorization: Bearer <token>
  ```

Routes are protected by declaring middleware in `routes/web.php`. The middleware is noted per endpoint below.

---

## Endpoints

### Public (no authentication required)

---

#### `GET /`

Health check.

**Response 200:**
```json
{ "app": "NetMon", "status": "ok" }
```

---

#### `POST /auth/login`

Authenticate with username or email and password. Sets a session cookie on success.

**Body:**
```json
{ "identity": "username or email", "password": "plaintext" }
```

**Response 200:**
```json
{
  "user": {
    "id": 1,
    "username": "alice",
    "email": "alice@example.com",
    "is_active": true,
    "created_at": "2024-01-01 00:00:00",
    "updated_at": "2024-01-01 00:00:00"
  }
}
```

**Response 400:** missing `identity` or `password`  
**Response 401:** invalid credentials (message intentionally vague — does not reveal whether the identity exists)

---

#### `POST /auth/logout`

Destroy the current session and expire the session cookie.

**Response 200:**
```json
{ "success": true }
```

---

### Session-protected (`SessionAuth` middleware)

These endpoints require an active session (i.e., the client must have called `POST /auth/login` first).  
A missing or invalid session returns **401**.

---

#### `GET /auth/me`

Return the currently authenticated user.

**Response 200:**
```json
{
  "user": {
    "id": 1,
    "username": "alice",
    "email": "alice@example.com",
    "is_active": true,
    "created_at": "2024-01-01 00:00:00",
    "updated_at": "2024-01-01 00:00:00"
  }
}
```

**Response 401:** not authenticated

---

#### `GET /api/tokens`

List all API tokens for the current user. Token hashes are never included.

**Response 200:**
```json
{
  "tokens": [
    {
      "id": 1,
      "user_id": 1,
      "name": "CI deploy key",
      "last_used_at": "2024-06-01 10:00:00",
      "expires_at": null,
      "revoked_at": null,
      "created_at": "2024-01-15 09:00:00"
    }
  ]
}
```

---

#### `POST /api/tokens`

Create a new API token. The raw token is returned **once only** — it cannot be retrieved again.

**Body:**
```json
{
  "name": "CI deploy key",
  "expires_at": "2025-12-31 00:00:00"
}
```

- `name` — required, non-empty string
- `expires_at` — optional; ISO datetime string (`Y-m-d H:i:s`); must be in the future; omit or pass `null` for a non-expiring token

**Response 201:**
```json
{
  "token": "a3f8e2...64 hex chars...d91c",
  "record": {
    "id": 2,
    "user_id": 1,
    "name": "CI deploy key",
    "last_used_at": null,
    "expires_at": "2025-12-31 00:00:00",
    "revoked_at": null,
    "created_at": "2024-06-01 12:00:00"
  }
}
```

**Response 400:** missing `name`, or `expires_at` is in the past

---

#### `DELETE /api/tokens/{id}`

Revoke one of the current user's tokens. Ownership is enforced — users cannot revoke other users' tokens.

**Response 200:**
```json
{ "success": true }
```

**Response 400:** `id` is not a positive integer  
**Response 404:** token not found, already revoked, or owned by a different user

---

### Token-protected (`TokenAuth` middleware)

No endpoints currently use `TokenAuth` middleware. The infrastructure is fully implemented — the `Authorization: Bearer` flow, principal resolution, and permission checking all work. Routes using `TokenAuth` will be added as API features are built.

To protect a route with token auth:

```php
$router->get('/api/some/resource', 'SomeController@method', ['TokenAuth']);

// Or with a permission check:
$router->get('/api/some/resource', 'SomeController@method', ['TokenAuth', 'RequirePermission:resource.view']);
```

---

## Route Summary

| Method | Path | Middleware | Controller |
|---|---|---|---|
| GET | `/` | — | `HomeController@index` |
| POST | `/auth/login` | — | `AuthController@login` |
| POST | `/auth/logout` | — | `AuthController@logout` |
| GET | `/auth/me` | `SessionAuth` | `AuthController@me` |
| GET | `/api/tokens` | `SessionAuth` | `TokenController@index` |
| POST | `/api/tokens` | `SessionAuth` | `TokenController@create` |
| DELETE | `/api/tokens/{id}` | `SessionAuth` | `TokenController@revoke` |

---

## See Also

- [auth.md](auth.md) — Authentication internals, session config, token security model
- [architecture.md](architecture.md) — Middleware stack, request lifecycle
