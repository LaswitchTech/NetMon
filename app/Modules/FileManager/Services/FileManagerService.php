<?php

namespace App\Modules\FileManager\Services;

/**
 * Filesystem operations for the File Manager module.
 *
 * All path arguments are resolved through PathResolver before any system call,
 * so traversal outside the configured root is structurally impossible from
 * this service.
 *
 * Returned data is plain PHP arrays — no domain objects.
 * Exceptions are \RuntimeException with user-safe messages.
 *
 * This service has no dependency on NetMon-specific code and can be reused
 * in any application that needs safe file management.
 *
 * Phase 1 capabilities:
 *   - listDirectory()  list immediate children of a directory
 *   - stat()           metadata for one file or directory
 *   - mkdir()          create a new empty directory
 *   - upload()         move a PHP upload to the target directory
 *   - delete()         delete a file or an EMPTY directory
 *   - absolutePath()   return the canonical absolute path (for downloads)
 *
 * Phase 1 constraints:
 *   - delete() refuses non-empty directories (no recursive delete)
 *   - No preview, thumbnail, or content-inspection logic
 */
class FileManagerService
{
    private PathResolver $resolver;

    public function __construct(PathResolver $resolver)
    {
        $this->resolver = $resolver;
    }

    // -------------------------------------------------------------------------
    // Read operations
    // -------------------------------------------------------------------------

    /**
     * List the immediate children of a directory.
     *
     * Each entry array:
     *   name      string   Entry name
     *   type      string   'dir' or 'file'
     *   size      int|null Byte size (null for directories)
     *   modified  string   Last-modified timestamp, 'Y-m-d H:i:s'
     *   path      string   Root-relative path (forward slashes) — use for URLs
     *
     * Entries are sorted: directories first, then files; both alphabetically.
     *
     * @param  string $rootPath     Absolute path to the storage root.
     * @param  string $relativePath Path relative to root ('' = root directory).
     * @return array<int, array>
     * @throws \RuntimeException    If the path does not exist or is not a directory.
     */
    public function listDirectory(string $rootPath, string $relativePath): array
    {
        $dir = $this->resolver->resolve($rootPath, $relativePath);

        if (!is_dir($dir)) {
            throw new \RuntimeException("Not a directory.");
        }

        $handle = opendir($dir);

        if ($handle === false) {
            throw new \RuntimeException("Cannot open directory.");
        }

        $entries = [];

        while (($entry = readdir($handle)) !== false) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $absPath = $dir . DIRECTORY_SEPARATOR . $entry;
            $isDir   = is_dir($absPath);
            $relPath = $this->resolver->relativize($rootPath, $absPath);

            $entries[] = [
                'name'     => $entry,
                'type'     => $isDir ? 'dir' : 'file',
                'size'     => $isDir ? null : (int) filesize($absPath),
                'modified' => date('Y-m-d H:i:s', (int) filemtime($absPath)),
                'path'     => $relPath,
            ];
        }

        closedir($handle);

        // Directories first, then files — each group alphabetical (case-insensitive).
        usort($entries, function (array $a, array $b): int {
            if ($a['type'] !== $b['type']) {
                return $a['type'] === 'dir' ? -1 : 1;
            }
            return strcasecmp($a['name'], $b['name']);
        });

        return $entries;
    }

    /**
     * Return metadata for a single file or directory.
     *
     * @param  string $rootPath     Absolute path to the storage root.
     * @param  string $relativePath Path relative to root.
     * @return array{name: string, type: string, size: int|null, modified: string, path: string}
     * @throws \RuntimeException    If the path does not exist.
     */
    public function stat(string $rootPath, string $relativePath): array
    {
        $abs = $this->resolver->resolve($rootPath, $relativePath);

        return [
            'name'     => basename($abs),
            'type'     => is_dir($abs) ? 'dir' : 'file',
            'size'     => is_file($abs) ? (int) filesize($abs) : null,
            'modified' => date('Y-m-d H:i:s', (int) filemtime($abs)),
            'path'     => $relativePath,
        ];
    }

    /**
     * Return the canonical absolute path for a file (used for streaming downloads).
     *
     * @throws \RuntimeException  If the path doesn't exist or is a directory.
     */
    public function absolutePath(string $rootPath, string $relativePath): string
    {
        $abs = $this->resolver->resolve($rootPath, $relativePath);

        if (is_dir($abs)) {
            throw new \RuntimeException("Cannot download a directory.");
        }

        return $abs;
    }

    // -------------------------------------------------------------------------
    // Write operations
    // -------------------------------------------------------------------------

    /**
     * Create a new directory.
     *
     * @param  string $rootPath       Absolute path to the storage root.
     * @param  string $relativeParent Parent directory (relative to root, '' = root).
     * @param  string $name           Name of the new directory (no separators).
     * @throws \RuntimeException      On invalid name, traversal, existing path, or I/O failure.
     */
    public function mkdir(string $rootPath, string $relativeParent, string $name): void
    {
        $newPath = $this->resolver->resolveNew($rootPath, $relativeParent, $name);

        if (file_exists($newPath)) {
            throw new \RuntimeException("A file or directory named \"{$name}\" already exists.");
        }

        if (!mkdir($newPath, 0755, false)) {
            throw new \RuntimeException("Failed to create directory \"{$name}\".");
        }
    }

    /**
     * Move a PHP file upload into the target directory.
     *
     * Sanitizes the original filename: keeps only the basename, strips control
     * characters, and rejects empty or dot-only names.
     *
     * @param  string $rootPath       Absolute path to the storage root.
     * @param  string $relativeParent Target directory (relative to root, '' = root).
     * @param  array  $uploadedFile   One entry from $_FILES (tmp_name, name, error).
     * @throws \RuntimeException      On upload error, invalid name, traversal, or I/O failure.
     */
    public function upload(string $rootPath, string $relativeParent, array $uploadedFile): void
    {
        $errorCode = $uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE;

        if ($errorCode !== UPLOAD_ERR_OK) {
            $messages = [
                UPLOAD_ERR_INI_SIZE   => 'File exceeds the server upload size limit.',
                UPLOAD_ERR_FORM_SIZE  => 'File exceeds the form upload size limit.',
                UPLOAD_ERR_PARTIAL    => 'File was only partially uploaded.',
                UPLOAD_ERR_NO_FILE    => 'No file was uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Temporary upload directory is missing.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write upload to disk.',
                UPLOAD_ERR_EXTENSION  => 'Upload stopped by a PHP extension.',
            ];
            throw new \RuntimeException($messages[$errorCode] ?? "File upload failed (error {$errorCode}).");
        }

        // Sanitize filename: basename only, no control characters.
        $safeName = basename($uploadedFile['name'] ?? '');
        $safeName = preg_replace('/[\x00-\x1F\x7F]/', '', $safeName);

        if ($safeName === '' || $safeName === '.' || $safeName === '..') {
            throw new \RuntimeException("Invalid file name.");
        }

        $targetPath = $this->resolver->resolveNew($rootPath, $relativeParent, $safeName);

        if (!move_uploaded_file($uploadedFile['tmp_name'], $targetPath)) {
            throw new \RuntimeException("Failed to save uploaded file.");
        }
    }

    /**
     * Delete a file or an empty directory.
     *
     * Phase 1: non-empty directories are refused. This prevents accidental mass deletion.
     *
     * @param  string $rootPath     Absolute path to the storage root.
     * @param  string $relativePath Path relative to root (must not be '').
     * @throws \RuntimeException    If path equals root, dir is non-empty, or deletion fails.
     */
    public function delete(string $rootPath, string $relativePath): void
    {
        $abs = $this->resolver->resolve($rootPath, $relativePath);

        // Never delete the root itself.
        $canonicalRoot = realpath($rootPath);
        if ($abs === $canonicalRoot) {
            throw new \RuntimeException("Cannot delete the storage root.");
        }

        if (is_dir($abs)) {
            // Phase 1 safety: refuse to delete non-empty directories.
            $items    = scandir($abs);
            $nonDots  = array_filter($items ?: [], fn($i) => $i !== '.' && $i !== '..');

            if (!empty($nonDots)) {
                throw new \RuntimeException("Directory is not empty. Remove its contents before deleting the folder.");
            }

            if (!rmdir($abs)) {
                throw new \RuntimeException("Failed to delete directory.");
            }
        } else {
            if (!unlink($abs)) {
                throw new \RuntimeException("Failed to delete file.");
            }
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Format a byte count as a human-readable string (B, KB, MB, GB).
     */
    public static function formatBytes(int $bytes): string
    {
        if ($bytes >= 1_073_741_824) {
            return number_format($bytes / 1_073_741_824, 1) . ' GB';
        }
        if ($bytes >= 1_048_576) {
            return number_format($bytes / 1_048_576, 1) . ' MB';
        }
        if ($bytes >= 1_024) {
            return number_format($bytes / 1_024, 1) . ' KB';
        }
        return $bytes . ' B';
    }
}
