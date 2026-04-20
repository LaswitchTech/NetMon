# Notes Module

> **Status (Phase 14):** Notes module is implemented and integrated into the Device Detail, Alert Detail, and Discovery Detail pages.
> Notes can be added and deleted (by their author) from `/devices/{id}`, `/alerts/{id}`, and `/discovery/{id}`.
> The module is reusable — it has no dependency on NetMon-specific classes.
> A shared partial (`app/Views/partials/notes-section.php`) renders the notes UI consistently across all three entity pages.
>
> Related: [architecture.md](architecture.md) · [devices.md](devices.md) · [alerts.md](alerts.md) · [discovery.md](discovery.md) · [domain-model.md](domain-model.md)

---

## Purpose

The Notes module is a **reusable, entity-agnostic note-attachment system**. It allows free-text notes to be attached to any domain object (device, alert, discovery finding, or any future entity) without coupling the module to NetMon-specific models.

Notes are intended for human context: an operator recording why a device was merged, what an alert means, or what action was taken during an incident. They are not events, not audit entries, and not structured data — they are narrative annotations.

---

## Design Principles

- **Polymorphic.** Notes target any entity via `entity_type` (string) + `entity_id` (int). No entity-specific foreign keys exist in the notes table.
- **Non-destructive.** Deleting a device, alert, or finding does NOT cascade-delete its notes. Notes are preserved for historical context even if the entity they describe no longer exists.
- **Author-preserving.** The note author (`user_id`) is recorded. If the user account is later deleted, `user_id` is set to NULL via `ON DELETE SET NULL` — the note survives as an anonymous annotation.
- **No assumption of entity type.** The Notes module has no `require` or `use` of `DeviceRepository`, `AlertRepository`, or any NetMon-specific class. It knows only: type string, entity ID, user ID, and content.
- **Reusable outside NetMon.** The module lives in `app/Modules/Notes/` and can be included in any future app built on this platform.

---

## Module Location

```
app/Modules/Notes/
    Models/
        NoteRepository.php      ← All DB queries for the notes table
    Services/
        NoteService.php         ← Validation + write orchestration (addNote, removeNote)
```

NetMon consumes the module from the application layer:

```
app/NetMon/Controllers/
    DeviceController.php        ← addNote(), deleteNote() — entity_type 'device'
    AlertController.php         ← addNote(), deleteNote() — entity_type 'alert'
    DiscoveryController.php     ← addNote(), deleteNote() — entity_type 'finding'
app/Views/
    partials/
        notes-section.php       ← Shared UI partial (list + form + error flash)
    devices/
        show.php                ← includes partials/notes-section.php
    alerts/
        show.php                ← includes partials/notes-section.php
    discovery/
        show.php                ← includes partials/notes-section.php
```

---

## Schema

### `notes`

Migration: **`0021_create_notes_table.php`**

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | INTEGER PK | No | — | Auto-increment |
| `entity_type` | VARCHAR(64) | No | — | Entity kind: `device`, `alert`, `finding`, etc. |
| `entity_id` | INTEGER | No | — | Primary key of the target entity. **Not a FK** — entity may be deleted. |
| `user_id` | INTEGER | Yes | NULL | → `users.id` SET NULL on user delete. NULL = author's account was deleted. |
| `content` | TEXT | No | — | Note body. No DB length limit; service enforces 10 000 character cap. |
| `created_at` | VARCHAR(32) | No | — | When the note was created |
| `updated_at` | VARCHAR(32) | No | — | When the note was last edited (same as `created_at` on insert) |

**Indexes:**
- `notes_entity (entity_type, entity_id)` — fetch all notes for a given entity (hot path)
- `notes_user_id` — all notes by a given user

**No cascade delete on entity.** There is no FK on `entity_id`. Notes survive entity deletion.

**Why no FK on `entity_id`?**
A polymorphic association (`entity_type` + `entity_id`) cannot be expressed as a single referential FK in SQL — the target table depends on `entity_type`. Keeping `entity_id` FK-free is the standard pattern. Application code verifies the entity exists before creating a note.

---

## NoteRepository

**Class:** `App\Modules\Notes\Models\NoteRepository`

| Method | Description |
|--------|-------------|
| `findByEntity(string $type, int $entityId): array` | Return all notes for an entity, newest first. JOINs `users` for `author_name` and `author_display`. |
| `findById(int $id): ?array` | Return a single note by ID with author JOIN; null if not found |
| `create(array $data): int` | Insert a note row; set both `created_at` and `updated_at` to now; return new ID |
| `delete(int $id): void` | Hard-delete a note row (no soft-delete; removal is intentional) |

### Row shape returned by `findByEntity` / `findById`

```php
[
    'id'             => int,
    'entity_type'    => string,
    'entity_id'      => int,
    'user_id'        => int|null,
    'content'        => string,
    'created_at'     => string,
    'updated_at'     => string,
    'author_name'    => string|null,   // users.username  — NULL if author deleted
    'author_display' => string|null,   // users.display_name — NULL if not set or author deleted
]
```

---

## NoteService

**Class:** `App\Modules\Notes\Services\NoteService`

| Method | Description |
|--------|-------------|
| `addNote(string $entityType, int $entityId, ?int $userId, string $content): int` | Validate and create a note; return new ID |
| `removeNote(int $noteId, ?int $requestingUserId, bool $isAdmin = false): void` | Validate ownership and delete |

**Validation rules:**
- `content` must not be empty after trimming
- `content` maximum: 10 000 characters (`NoteService::MAX_CONTENT_LENGTH`)
- Both violations throw `\InvalidArgumentException`

**Authorization rules (v1):**
- A note can be deleted by its **author** (`user_id == requestingUserId`)
- If `$isAdmin = true`, any note can be deleted regardless of authorship (reserved for future admin capability; not yet wired to a permission in the UI)
- Notes with `user_id = NULL` (anonymous / deleted author) can only be deleted when `$isAdmin = true` — they have no owner to authorize. The UI does not show a delete button for these notes.

---

## NetMon Integration

All three integrations follow the same controller pattern. Each controller:

1. Loads notes in `show()` via `NoteRepository::findByEntity($type, $id)`
2. Exposes `addNote()` and `deleteNote()` POST handlers
3. Delegates all validation and authorization to `NoteService` — no duplicate logic in controllers

### Shared View Partial

**`app/Views/partials/notes-section.php`**

All three entity pages include this single partial. The caller sets two variables before the `require`:

```php
$noteBaseUrl = '/devices/' . (int) $device['id'];  // or '/alerts/{id}', '/discovery/{id}'
require __DIR__ . '/../partials/notes-section.php';
```

The partial uses `$noteBaseUrl` to build all form `action` attributes. `$notes` and `$user` are already in scope from the controller.

**Features:**
- **Note list:** each note as a styled block (`var(--app-panel-2)` background), author display name, timestamp, content (`white-space: pre-wrap`)
- **Author display:** `display_name` → `username` → "(deleted user)" if `user_id` is NULL
- **Edited indicator:** "(edited)" label when `updated_at ≠ created_at`
- **Delete button:** shown only on notes authored by the current user; `window.confirm()` before submit
- **Empty state:** "No notes yet. Add one below."
- **Add note form:** `<textarea>` (rows=3, maxlength=10000) + submit button
- **Error flash:** `?note_error=` query parameter renders a dismissible Bootstrap alert above the list
- **Anchor:** card `id="notes"` — redirect URLs use `#notes` to scroll directly to the section

**Why not DataTables?** Notes are prose blocks with multi-line content and per-note action buttons. A tabular layout would not render note content well. The card-per-note pattern is an intentional exception to the DataTables default, documented here.

---

### Device Detail (`entity_type = 'device'`)

**Controller:** `App\NetMon\Controllers\DeviceController`

```php
// show():
$notes = $noteRepo->findByEntity('device', $deviceId);

// addNote():
$service->addNote('device', $deviceId, (int) $user['id'], $content);

// deleteNote():
$service->removeNote($noteId, (int) $user['id']);
```

**Routes:**
```php
POST /devices/{id}/notes                    → DeviceController@addNote    [WebAuth]
POST /devices/{id}/notes/{noteId}/delete    → DeviceController@deleteNote [WebAuth]
```

---

### Alert Detail (`entity_type = 'alert'`)

**Controller:** `App\NetMon\Controllers\AlertController`

```php
// show():
$notes = $noteRepo->findByEntity('alert', $alertId);

// addNote():
$service->addNote('alert', $alertId, (int) $user['id'], $content);

// deleteNote():
$service->removeNote($noteId, (int) $user['id']);
```

**Routes:**
```php
POST /alerts/{id}/notes                    → AlertController@addNote    [WebAuth]
POST /alerts/{id}/notes/{noteId}/delete    → AlertController@deleteNote [WebAuth]
```

Notes section placed after the Notification History card in `app/Views/alerts/show.php`.

---

### Discovery Detail (`entity_type = 'finding'`)

**Controller:** `App\NetMon\Controllers\DiscoveryController`

The entity_type is `'finding'` (not `'discovery_finding'`) — kept short and consistent with the domain language used throughout the codebase (the model is `DiscoveryFinding`, the variable is `$finding`).

```php
// show():
$notes = $noteRepo->findByEntity('finding', $findingId);

// addNote():
$service->addNote('finding', $findingId, (int) $user['id'], $content);

// deleteNote():
$service->removeNote($noteId, (int) $user['id']);
```

**Routes:**
```php
POST /discovery/{id}/notes                    → DiscoveryController@addNote    [WebAuth]
POST /discovery/{id}/notes/{noteId}/delete    → DiscoveryController@deleteNote [WebAuth]
```

Notes section placed after the Possible Device Matches card (or directly after the main row when there are no matches) in `app/Views/discovery/show.php`.

---

## Integrated Entity Types

| Entity | `entity_type` value | Where notes appear | Status |
|--------|--------------------|--------------------|--------|
| Device | `device` | `/devices/{id}` | **Done** |
| Alert | `alert` | `/alerts/{id}` | **Done** |
| Discovery finding | `finding` | `/discovery/{id}` | **Done** |

### Adding notes to a future entity type

1. Load notes in the relevant controller's `show()` method:
   ```php
   $noteRepo = new NoteRepository($this->container->get('db'));
   $notes    = $noteRepo->findByEntity('my_entity', $id);
   ```
2. Add `addNote()` and `deleteNote()` controller methods (identical pattern to the existing three)
3. Register POST routes for both handlers
4. In the view, set `$noteBaseUrl` and include the partial:
   ```php
   $noteBaseUrl = '/my-entity/' . (int) $entity['id'];
   require __DIR__ . '/../partials/notes-section.php';
   ```

No schema change is required — the polymorphic model supports any future entity type without migration.

---

## What Is NOT Yet in Scope

| Item | Notes |
|------|-------|
| Note editing | Edit form + `NoteRepository::update()` stub present; not yet wired to a route or UI |
| Threaded notes / replies | Deferred |
| Note visibility (private/internal/public) | Deferred |
| Pinning / highlighting | Deferred |
| Attachments / file links | Deferred |
| Notifications triggered by notes | Deferred — would use the Notifications module once built |
| Admin delete (any note) | `NoteService::removeNote(..., isAdmin: true)` is implemented; not yet wired to a permission check |
| Notes on alerts | Backend ready; UI integration deferred to a future phase |
| Notes on discovery findings | Backend ready; UI integration deferred to a future phase |

---

## Implementation Checklist

- [x] Migration `0021_create_notes_table.php`
- [x] `app/Modules/Notes/Models/NoteRepository.php`
- [x] `app/Modules/Notes/Services/NoteService.php`
- [x] `app/Views/partials/notes-section.php` — shared UI partial
- [x] `DeviceController::show()` — load notes
- [x] `DeviceController::addNote()` — POST handler
- [x] `DeviceController::deleteNote()` — POST handler (author-only)
- [x] `app/Views/devices/show.php` — includes notes partial
- [x] Route: `POST /devices/{id}/notes`
- [x] Route: `POST /devices/{id}/notes/{noteId}/delete`
- [x] `AlertController::show()` — load notes
- [x] `AlertController::addNote()` — POST handler
- [x] `AlertController::deleteNote()` — POST handler (author-only)
- [x] `app/Views/alerts/show.php` — includes notes partial
- [x] Route: `POST /alerts/{id}/notes`
- [x] Route: `POST /alerts/{id}/notes/{noteId}/delete`
- [x] `DiscoveryController::show()` — load notes
- [x] `DiscoveryController::addNote()` — POST handler
- [x] `DiscoveryController::deleteNote()` — POST handler (author-only)
- [x] `app/Views/discovery/show.php` — includes notes partial
- [x] Route: `POST /discovery/{id}/notes`
- [x] Route: `POST /discovery/{id}/notes/{noteId}/delete`
- [x] Update `docs/notes-module.md`
- [x] Update `docs/alerts.md`
- [x] Update `docs/discovery.md`
- [ ] Note editing UI
- [ ] Admin delete (any note) — `NoteService::removeNote(..., isAdmin: true)` is implemented; not yet wired to a permission check
