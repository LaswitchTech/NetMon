# File Manager Module

## Overview

The File Manager is a reusable module that provides safe, browser-based access to files inside explicitly configured storage roots. It has no dependency on any NetMon-specific model or service.

| Property | Value |
|---|---|
| Module path | `app/Modules/FileManager/` |
| Views | `app/Views/file-manager/` |
| Config | `config/filemanager.php` |
| Permission | `files.manage` |
| Routes | `GET/POST /files/*` |

---

## Storage Root Model

Roots are declared in `config/filemanager.php`. Each root is a plain PHP array:

```php
return [
    'roots' => [
        [
            'id'      => 'storage',       // URL-safe slug; appears in route segments
            'label'   => 'Application Storage', // Human-readable name
            'path'    => dirname(__DIR__) . '/storage/files', // Absolute filesystem path
            'enabled' => true,            // false = hidden from UI and all operations refused
        ],
    ],
];
```

**Rules for roots:**

- `id` must be URL-safe: lowercase letters, digits, hyphens, underscores.
- `path` must be an absolute path to an existing, readable/writable directory.
- Disabled roots (`enabled: false`) are invisible to all controller methods.
- Local overrides can be placed in `config/local.php` under the `filemanager` key (see `Config::load()`).

To add a root for a new project:

1. Add the entry to `config/filemanager.php`.
2. Create the directory and set correct permissions.
3. Grant users the `files.manage` permission via Admin → Groups.

---

## Safety Model

Path safety is enforced exclusively by `PathResolver` (`app/Modules/FileManager/Services/PathResolver.php`). Every filesystem call in `FileManagerService` goes through one of two resolver methods before touching the disk.

### `resolve(rootPath, relativePath)` — for existing paths

```
1. realpath(rootPath)      → canonical root (resolves symlinks, .., etc.)
2. realpath(joined path)   → canonical target
3. Verify: target === root  OR  target starts with (root + DIRECTORY_SEPARATOR)
4. If check fails → throw RuntimeException("Path traversal detected")
```

The separator-aware prefix check prevents the false-positive where `/storage/files-extra` would match root `/storage/files`.

### `resolveNew(rootPath, relativeParent, name)` — for paths that don't exist yet

Used by mkdir and upload (target doesn't exist yet so `realpath()` would return false).

```
1. Validate name: not empty, not ".", not "..", no "/" or "\" characters, no control chars
2. resolve(rootPath, relativeParent)  → canonical parent (must exist, must be within root)
3. Verify parent is_dir()
4. Construct: parent + DIRECTORY_SEPARATOR + name
5. Check: result starts with (canonicalRoot + DIRECTORY_SEPARATOR)
```

Because the parent is already canonical and within root, and the name contains no separators, the constructed path is guaranteed to be within root without needing `realpath()`.

### What this prevents

| Attack | Prevention |
|---|---|
| `../../../etc/passwd` | `resolve()` catches it via `realpath()` + prefix check |
| `name` containing `/` | `resolveNew()` rejects names with any path separator |
| Symlink escape | `realpath()` resolves symlinks before the prefix check |
| Null bytes in filenames | `resolveNew()` strips control characters from upload names |
| Deleting root | `delete()` explicitly rejects paths that equal the canonical root |

---

## Routes and Controller

**File:** `app/Modules/FileManager/Controllers/FileManagerController.php`

All routes require `['WebAuth', 'WebPermission:files.manage']`.

| Method | Route | Controller method | Description |
|---|---|---|---|
| GET | `/files` | `index()` | Root list — cards for each enabled root |
| GET | `/files/{rootId}` | `browse()` | Directory listing; `?path=rel/path` for subdirs |
| GET | `/files/{rootId}/download` | `download()` | Stream file; `?path=rel/path` |
| POST | `/files/{rootId}/mkdir` | `mkdir()` | Create directory; body: `path`, `name` |
| POST | `/files/{rootId}/upload` | `upload()` | Upload file; multipart; body: `path`, `file` |
| POST | `/files/{rootId}/delete` | `delete()` | Delete file or empty directory; body: `path` |

Route ordering: the static sub-paths (`download`, `mkdir`, `upload`, `delete`) are registered before `{rootId}` to prevent collisions.

### Path flow for a browse request

```
GET /files/storage?path=reports/2024
  → FileManagerController::browse()
    → sanitizeRelativePath("reports/2024")  → "reports/2024"
    → PathResolver::resolve("/project/storage/files", "reports/2024")
      → realpath("/project/storage/files")            = "/project/storage/files"
      → realpath("/project/storage/files/reports/2024") = "/project/storage/files/reports/2024"
      → prefix check: OK
    → FileManagerService::listDirectory("/project/storage/files", "reports/2024")
      → returns entry array
    → browse.php view renders entries
```

---

## Services

### `PathResolver`

**File:** `app/Modules/FileManager/Services/PathResolver.php`

| Method | Purpose |
|---|---|
| `resolve(rootPath, relativePath): string` | Resolve existing path within root |
| `resolveNew(rootPath, relativeParent, name): string` | Resolve new-entry path (mkdir/upload) |
| `relativize(rootPath, absolutePath): string` | Convert absolute path back to root-relative (for URLs) |

### `FileManagerService`

**File:** `app/Modules/FileManager/Services/FileManagerService.php`

| Method | Returns | Description |
|---|---|---|
| `listDirectory(rootPath, relativePath)` | `array[]` | List immediate children; dirs first, alpha |
| `stat(rootPath, relativePath)` | `array` | Metadata for one entry |
| `absolutePath(rootPath, relativePath)` | `string` | Canonical path for download streaming |
| `mkdir(rootPath, relativeParent, name)` | `void` | Create empty directory |
| `upload(rootPath, relativeParent, uploadedFile)` | `void` | Move PHP upload to target |
| `delete(rootPath, relativePath)` | `void` | Delete file or empty directory |
| `formatBytes(bytes)` | `string` | Static: human-readable size (B/KB/MB/GB) |

Directory listing entry shape:
```php
[
    'name'     => 'report.pdf',       // Entry name
    'type'     => 'file',             // 'file' or 'dir'
    'size'     => 204800,             // bytes; null for dirs
    'modified' => '2025-04-20 09:30:00',
    'path'     => 'reports/report.pdf', // root-relative, forward slashes (use for URLs)
]
```

---

## Authorization

A single permission gates all File Manager routes:

| Permission | Description | Granted to |
|---|---|---|
| `files.manage` | Browse, upload, download, and delete files in configured storage roots | `admin` group (default) |

The permission is seeded by migration `0027_add_files_manage_permission` and included in `database/seeds/AdminBootstrap.php` for fresh installs.

To grant File Manager access to a non-admin group:
1. Admin → Groups → Edit the group
2. Check the `files.manage` permission checkbox

Future phases may split this into `files.read` and `files.write` if read-only access is needed.

---

## UI

### Root index (`GET /files`)

Shows a card for each enabled root. If no roots are configured, a guidance message is shown.

### Browser (`GET /files/{rootId}`)

- **Breadcrumb navigation** — click any segment to navigate up the tree
- **Flash messages** — success/error from mkdir, upload, delete
- **New Folder panel** — collapsible; POST to `mkdir`
- **Upload File panel** — collapsible; multipart POST to `upload`
- **Directory listing** — DataTable with:
  - Hidden sort column keeps directories always before files (`orderFixed: { pre: [[0, 'asc']] }`)
  - Folder names are clickable links that navigate into the subdirectory
  - Download button for files
  - Delete button (with JavaScript `confirm()` dialog) for both files and directories

### Delete safety

Destructive delete requires two confirmations:
1. The JavaScript `confirm()` dialog in the browser
2. Server-side: `delete()` refuses non-empty directories, preventing accidental mass deletion

---

## Phase 1 Constraints

| Constraint | Reason | Future path |
|---|---|---|
| Delete refuses non-empty directories | Prevents accidental mass deletion | Phase 2: opt-in recursive delete with explicit confirmation |
| No file preview | Avoids MIME/security complexity | Phase 2: text preview; Phase 3: images/PDF |
| No rename | Not implemented | Phase 2: simple rename/move within root |
| No move/copy | Not implemented | Phase 2 |
| Single permission (`files.manage`) | Sufficient for Phase 1 | Phase 2: `files.read` + `files.write` split |
| No access log | Not audited | Future: integrate with audit log for write operations |
| No per-root permissions | All enabled roots share `files.manage` | Future: per-root permission binding |
| Config-file roots only | No runtime root management | Future: DB-managed roots with per-root metadata |

---

## What Remains Before Feature-Complete

| Feature | Notes |
|---|---|
| File preview | Text, image, PDF — browser-rendered inline |
| Thumbnails | Image thumbnails in directory listing |
| Rename / move | Single-entry rename; move within or across roots |
| Recursive delete | Multi-select + confirmation UI |
| File attachments | Link file manager entries to NetMon entities (devices, alerts) |
| Access log | Record upload/download/delete in admin audit log |
| Per-root permissions | Bind a group permission per root, not just a global flag |
| DB-managed roots | Admin UI to add/edit/disable roots at runtime |
| Multi-file upload | Drag-and-drop zone with multiple file selection |
| Zip download | Package a folder as a .zip for download |
| Search | File search within a root or across all roots |

---

## Extending the Module

### Adding a new storage root

1. Add an entry to `config/filemanager.php`:
   ```php
   [
       'id'      => 'reports',
       'label'   => 'Monthly Reports',
       'path'    => '/var/data/reports',
       'enabled' => true,
   ],
   ```
2. Create the directory with correct web-process permissions.
3. Grant `files.manage` to the intended groups (or rely on admin already having it).

### Adding a new operation

1. Add a method to `FileManagerService` — always resolve the path through `PathResolver` first.
2. Add a controller method in `FileManagerController`.
3. Register the route in `routes/web.php` (static sub-paths before `{rootId}`).
4. Add UI elements to `browse.php`.

### Reusing outside NetMon

`PathResolver` and `FileManagerService` have zero dependencies on NetMon code. To use them in another project:

1. Copy `app/Modules/FileManager/Services/` into the target project.
2. Instantiate: `new FileManagerService(new PathResolver())`.
3. Pass your root path and relative paths; handle `\RuntimeException` from the service.
4. Build a thin controller and config layer appropriate for the target framework.
