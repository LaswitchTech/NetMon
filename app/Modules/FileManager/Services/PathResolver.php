<?php

namespace App\Modules\FileManager\Services;

/**
 * Path safety layer for the File Manager module.
 *
 * Every filesystem operation in FileManagerService goes through this class.
 * It is the single place that enforces the "never escape the root" guarantee.
 *
 * ## Safety model
 *
 * resolve() — for paths that already exist:
 *   1. Canonicalize the root with realpath() (resolves symlinks, .., etc.)
 *   2. Canonicalize the target with realpath()
 *   3. Verify the target starts with canonicalRoot + DIRECTORY_SEPARATOR
 *      (or equals canonicalRoot exactly)
 *   Any deviation throws RuntimeException — the caller never receives an
 *   unsafe path back.
 *
 * resolveNew() — for paths that do not yet exist (mkdir, upload target):
 *   1. Validate the new name: no path separators, not empty, not "." or ".."
 *   2. Resolve the parent directory with resolve() (must exist and be within root)
 *   3. Construct target = parent + DIRECTORY_SEPARATOR + name
 *   Because the parent is already canonical and within the root, and the name
 *   contains no separators, the constructed target is always within the root
 *   without needing realpath() on a non-existent path.
 *
 * relativize() — convert an absolute path back to a root-relative path for URLs.
 *
 * This class has no dependencies on NetMon-specific code and can be reused as-is
 * in any application that needs safe filesystem access.
 */
class PathResolver
{
    /**
     * Resolve a relative path against a storage root.
     *
     * Returns the canonical absolute filesystem path.
     * Throws \RuntimeException if:
     *   - the root does not exist
     *   - the resolved path does not exist
     *   - the resolved path is outside the root (traversal attempt)
     *
     * @param  string $rootPath      Absolute path to the storage root.
     * @param  string $relativePath  Path relative to the root (may be empty for root itself).
     * @return string                Canonical absolute path within the root.
     * @throws \RuntimeException     On traversal, non-existent root, or non-existent path.
     */
    public function resolve(string $rootPath, string $relativePath): string
    {
        $canonicalRoot = realpath($rootPath);

        if ($canonicalRoot === false) {
            throw new \RuntimeException("Storage root does not exist: {$rootPath}");
        }

        // Normalize: strip leading slashes and backslashes, use forward slashes.
        $relative = ltrim(str_replace('\\', '/', $relativePath), '/');

        // Empty or dot-only means "the root itself".
        if ($relative === '' || $relative === '.') {
            return $canonicalRoot;
        }

        $joined   = $canonicalRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
        $resolved = realpath($joined);

        if ($resolved === false) {
            throw new \RuntimeException("Path does not exist.");
        }

        if (!$this->isWithinRoot($resolved, $canonicalRoot)) {
            throw new \RuntimeException("Path traversal detected.");
        }

        return $resolved;
    }

    /**
     * Resolve a target path for a new file or directory that does not yet exist.
     *
     * Validates the new entry name, resolves the parent directory, then constructs
     * the full target path.  The parent must already exist and be within the root.
     *
     * @param  string $rootPath      Absolute path to the storage root.
     * @param  string $relativeParent Relative path to the parent directory (may be '').
     * @param  string $name           Name of the new entry (no path separators).
     * @return string                 Absolute path for the new entry.
     * @throws \RuntimeException      On invalid name, traversal, or non-directory parent.
     */
    public function resolveNew(string $rootPath, string $relativeParent, string $name): string
    {
        $name = trim($name);

        if ($name === '' || $name === '.' || $name === '..') {
            throw new \RuntimeException("Invalid name: must not be empty or a dot-segment.");
        }

        if (preg_match('#[/\\\\]#', $name)) {
            throw new \RuntimeException("Invalid name: must not contain path separators.");
        }

        // Strip control characters and null bytes.
        $name = preg_replace('/[\x00-\x1F\x7F]/', '', $name);

        if ($name === '') {
            throw new \RuntimeException("Invalid name after sanitization.");
        }

        // Resolve the parent (it must exist and be within root).
        $parent = $this->resolve($rootPath, $relativeParent);

        if (!is_dir($parent)) {
            throw new \RuntimeException("Parent path is not a directory.");
        }

        $canonicalRoot = realpath($rootPath);
        $newPath       = $parent . DIRECTORY_SEPARATOR . $name;

        // The parent is canonical and within root; the name has no separators,
        // so the new path is always within root — but verify anyway.
        if (!$this->isWithinRoot($newPath, $canonicalRoot)) {
            throw new \RuntimeException("Path traversal detected in new name.");
        }

        return $newPath;
    }

    /**
     * Convert an absolute path back to a root-relative path.
     *
     * Returns a forward-slash-delimited relative path, or an empty string
     * when $absolutePath equals the root.
     *
     * @param  string $rootPath     Absolute path to the storage root.
     * @param  string $absolutePath Absolute path within the root.
     * @return string               Root-relative path (forward slashes), or '' for root.
     */
    public function relativize(string $rootPath, string $absolutePath): string
    {
        $canonicalRoot = realpath($rootPath);

        if ($canonicalRoot === false || $absolutePath === $canonicalRoot) {
            return '';
        }

        $prefix = $canonicalRoot . DIRECTORY_SEPARATOR;

        if (strncmp($absolutePath, $prefix, strlen($prefix)) === 0) {
            return str_replace(DIRECTORY_SEPARATOR, '/', substr($absolutePath, strlen($prefix)));
        }

        return '';
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Return true when $path is exactly $canonicalRoot or is inside it.
     *
     * Uses a separator-aware prefix check to avoid false positives where
     * /storage/files-extra would wrongly match root /storage/files.
     */
    private function isWithinRoot(string $path, string $canonicalRoot): bool
    {
        if ($path === $canonicalRoot) {
            return true;
        }

        return strncmp(
            $path,
            $canonicalRoot . DIRECTORY_SEPARATOR,
            strlen($canonicalRoot) + 1
        ) === 0;
    }
}
