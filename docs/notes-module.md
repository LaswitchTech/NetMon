# Notes Module

> **Status:** Planned — not yet implemented.
> This is a design document. No schema, service, or view exists yet.
>
> Related: [architecture.md](architecture.md) · [devices.md](devices.md) · [domain-model.md](domain-model.md)

---

## Purpose

The Notes module is a **reusable, entity-agnostic note-attachment system**. It allows free-text notes to be attached to any domain object (device, alert, discovery finding, or any future entity) without coupling the module to NetMon-specific models.

Notes are intended for human context: an operator recording why a device was merged, what an alert means, or what action was taken during an incident. They are not events, not audit entries, and not structured data — they are narrative annotations.

---

## Design Principles

- **Polymorphic.** Notes target any entity via `entity_type` (string) + `entity_id` (int). No entity-specific foreign keys exist in the notes table.
- **Non-destructive.** Deleting a device, alert, or finding does NOT cascade-delete its notes. Notes are preserved for historical context even if the entity they describe no longer exists.
- **Author-preserving.** The note author (`user_id`) is recorded and retained even if the user account is later deleted (stored as an ID; SET NULL on user delete preserves the note with a null author reference).
- **No assumption of entity type.** The Notes module has no `require` or `use` of `DeviceRepository`, `AlertRepository`, or any NetMon-specific class. It knows only: type string, entity ID, user ID, and content.
- **Reusable outside NetMon.** The module lives in `app/Modules/Notes/` and can be included in any future app built on this platform.

---

## Module Location

```
app/Modules/Notes/
    Models/
        NoteRepository.php      ← All DB queries for the notes table
    Services/
        NoteService.php         ← Validation + write orchestration
```

NetMon consumes the module from the application layer:

```
app/NetMon/Controllers/
    DeviceController.php        ← Calls NoteService to read/write device notes
app/Views/devices/
    show.php                    ← Renders the Notes section
```

---

## Schema

### `notes`

One row per note. Migration number: **0021** (next available).

| Column | Type | Nullable | Default | Description |
|--------|------|----------|---------|-------------|
| `id` | INTEGER PK | No | — | Auto-increment |
| `entity_type` | VARCHAR(64) | No | — | Identifies the type of entity: `device`, `alert`, `finding`, etc. |
| `entity_id` | INTEGER | No | — | The ID of the target entity. Not a FK — the entity may be deleted. |
| `user_id` | INTEGER | Yes | NULL | → `users.id` SET NULL. NULL if the author's account was deleted. |
| `content` | TEXT | No | — | The note body. No length limit at the DB layer; UI may impose a soft cap. |
| `created_at` | VARCHAR(32) | No | — | When the note was created |
| `updated_at` | VARCHAR(32) | No | — | When the note was last edited (same as created_at on insert) |

**Indexes:**
- `notes_entity (entity_type, entity_id)` — fetch all notes for a given entity (hot path)
- `notes_user_id` — all notes by a given user

**No cascade delete.** There is no FK on `entity_id`. Notes survive entity deletion.

**Why no FK on `entity_id`?**
The entity may be a device, an alert, or any future object. A single polymorphic FK is not expressible in relational SQL without per-entity FK columns or a check constraint. Keeping the column FK-free is the standard approach for polymorphic associations. Application-level queries enforce that the entity exists before creating a note.

---

## Repository

**Class:** `App\Modules\Notes\Models\NoteRepository`

| Method | Description |
|--------|-------------|
| `findByEntity(string $type, int $entityId): array` | Return all notes for an entity, newest first |
| `findById(int $id): ?array` | Return a single note by ID; null if not found |
| `create(array $data): int` | Insert a note row; return new ID |
| `update(int $id, string $content): void` | Update note content; set updated_at |
| `delete(int $id): void` | Hard-delete a note row (notes have no soft-delete — removal is intentional) |

### Returned row shape

```php
[
    'id'          => int,
    'entity_type' => string,
    'entity_id'   => int,
    'user_id'     => int|null,
    'content'     => string,
    'created_at'  => string,
    'updated_at'  => string,
]
```

For display, join `users` in the query to get the author's display name:

```sql
SELECT n.*, u.username AS author_name, u.display_name AS author_display
FROM   notes n
LEFT   JOIN users u ON u.id = n.user_id
WHERE  n.entity_type = ?
  AND  n.entity_id   = ?
ORDER  BY n.created_at DESC
```

---

## Service

**Class:** `App\Modules\Notes\Services\NoteService`

Thin validation + write layer. Calls `NoteRepository` internally.

| Method | Description |
|--------|-------------|
| `addNote(string $entityType, int $entityId, int $userId, string $content): int` | Validate and create a note; return new ID |
| `editNote(int $noteId, int $requestingUserId, string $content): void` | Validate ownership and update content |
| `removeNote(int $noteId, int $requestingUserId): void` | Validate ownership and delete |

**Validation rules:**
- `content` must not be empty after trimming
- `content` maximum: 10 000 characters (enforced in service, not DB)
- Edit/delete: only the original author or an admin may modify a note (ownership check against `user_id`)

---

## NetMon Integration Points

### Device notes (first use case)

The device detail page is the first consumer of this module.

**Controller change** (`DeviceController::show()`):
```php
$noteRepo = new \App\Modules\Notes\Models\NoteRepository($this->container->get('db'));
$notes    = $noteRepo->findByEntity('device', $id);
```

**View change** (`app/Views/devices/show.php`):
- New "Notes" section at the bottom of the page
- Renders existing notes (author, content, timestamp)
- Includes a POST form to add a new note
- Author display: `display_name` if set, else `username`; "(deleted user)" if `user_id` is null

**New route** (`routes/web.php`):
```
POST /devices/{id}/notes    → DeviceController::addNote()
```

### Future integration points (not yet designed)

| Entity | entity_type value | Where notes would appear |
|--------|------------------|-----------------------------|
| Alert | `alert` | Alert detail page |
| Discovery finding | `finding` | Finding detail page |

---

## UI Guidelines

- Notes section heading: "Notes"
- Empty state: "No notes yet. Add one below."
- Each note card shows: content, author (linked to user if available), relative or absolute timestamp
- Add note form: single `<textarea>` + submit button, inline below existing notes
- Do not implement edit/delete in the first pass — add-only is sufficient for the initial integration
- Edit/delete can be added later when operator workflows are better understood

---

## What Is NOT in Scope

- **Threaded notes / replies** — deferred
- **Note visibility** (private/internal/public) — deferred
- **Pinning / highlighting** — deferred
- **Attachments / file links** — deferred
- **Notifications triggered by notes** — deferred (would use the Notifications module once built)

---

## Implementation Checklist

When implementing this module:

- [ ] Migration `0021_create_notes_table.php`
- [ ] `app/Modules/Notes/Models/NoteRepository.php`
- [ ] `app/Modules/Notes/Services/NoteService.php`
- [ ] `DeviceController::show()` — load notes
- [ ] `DeviceController::addNote()` — POST handler
- [ ] `app/Views/devices/show.php` — Notes section
- [ ] Route: `POST /devices/{id}/notes`
- [ ] Update `docs/devices.md` with the Notes section description
