<?php

namespace App\Modules\FileManager\Controllers;

use App\Core\Config;
use App\Core\Controller;
use App\Modules\FileManager\Services\FileManagerService;
use App\Modules\FileManager\Services\PathResolver;

/**
 * File Manager controller.
 *
 * Routes (all protected by ['WebAuth', 'WebPermission:files.manage']):
 *
 *   GET  /files                       → index()     Root list
 *   GET  /files/{rootId}              → browse()    Directory listing (?path=rel/path)
 *   POST /files/{rootId}/mkdir        → mkdir()     Create directory
 *   POST /files/{rootId}/upload       → upload()    Upload file
 *   GET  /files/{rootId}/download     → download()  Stream file download (?path=rel/path)
 *   POST /files/{rootId}/delete       → delete()    Delete file or empty directory
 *
 * All paths are resolved through PathResolver before any filesystem operation.
 * Path traversal outside the configured root is structurally prevented.
 *
 * This controller is part of the reusable FileManager module and has no
 * dependency on any NetMon-specific repository or service.
 */
class FileManagerController extends Controller
{
    // -------------------------------------------------------------------------
    // GET /files — root list
    // -------------------------------------------------------------------------

    public function index(array $params = []): void
    {
        $principal   = $this->container->get('principal');
        $user        = $principal['user'];
        $permissions = $principal['permissions'];

        $appConfig   = $this->container->get('config');
        $appName     = $appConfig['name'] ?? 'NetMon';
        $displayName = ($user['display_name'] ?? '') !== '' ? $user['display_name'] : $user['username'];

        $fmConfig = Config::load('filemanager');
        $roots    = array_filter(
            $fmConfig['roots'] ?? [],
            fn($r) => !empty($r['enabled'])
        );

        $pageTitle     = 'File Manager';
        $activeSection = 'File Manager';
        $viewsPath     = __DIR__ . '/../../../Views';

        ob_start();
        require $viewsPath . '/file-manager/index.php';
        $content = ob_get_clean();

        require $viewsPath . '/layouts/app.php';
    }

    // -------------------------------------------------------------------------
    // GET /files/{rootId} — directory listing
    // -------------------------------------------------------------------------

    public function browse(array $params = []): void
    {
        $root = $this->findRoot($params['rootId'] ?? '');

        if ($root === null) {
            http_response_code(404);
            echo '<h1>404 &mdash; Storage root not found</h1>';
            return;
        }

        $relativePath = $this->sanitizeRelativePath($_GET['path'] ?? '');
        $service      = $this->makeService();

        try {
            $entries = $service->listDirectory($root['path'], $relativePath);
        } catch (\RuntimeException $e) {
            http_response_code(404);
            echo '<h1>404 &mdash; ' . htmlspecialchars($e->getMessage()) . '</h1>';
            return;
        }

        $principal   = $this->container->get('principal');
        $user        = $principal['user'];
        $permissions = $principal['permissions'];

        $appConfig   = $this->container->get('config');
        $appName     = $appConfig['name'] ?? 'NetMon';
        $displayName = ($user['display_name'] ?? '') !== '' ? $user['display_name'] : $user['username'];

        $breadcrumbs  = $this->buildBreadcrumbs($root, $relativePath);
        $flash        = $this->popFlash();
        $pageTitle    = 'File Manager';
        $activeSection = 'File Manager';
        $viewsPath    = __DIR__ . '/../../../Views';

        ob_start();
        require $viewsPath . '/file-manager/browse.php';
        $content = ob_get_clean();

        require $viewsPath . '/layouts/app.php';
    }

    // -------------------------------------------------------------------------
    // POST /files/{rootId}/mkdir — create directory
    // -------------------------------------------------------------------------

    public function mkdir(array $params = []): void
    {
        $root = $this->findRoot($params['rootId'] ?? '');

        if ($root === null) {
            http_response_code(404);
            echo '<h1>404 &mdash; Storage root not found</h1>';
            return;
        }

        $parent = $this->sanitizeRelativePath($_POST['path'] ?? '');
        $name   = trim($_POST['name'] ?? '');

        $service = $this->makeService();

        try {
            $service->mkdir($root['path'], $parent, $name);
            $this->flash('success', "Folder \"{$name}\" created.");
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }

        $this->redirectToBrowse($root['id'], $parent);
    }

    // -------------------------------------------------------------------------
    // POST /files/{rootId}/upload — upload file
    // -------------------------------------------------------------------------

    public function upload(array $params = []): void
    {
        $root = $this->findRoot($params['rootId'] ?? '');

        if ($root === null) {
            http_response_code(404);
            echo '<h1>404 &mdash; Storage root not found</h1>';
            return;
        }

        $parent = $this->sanitizeRelativePath($_POST['path'] ?? '');

        if (empty($_FILES['file'])) {
            $this->flash('error', 'No file received.');
            $this->redirectToBrowse($root['id'], $parent);
            return;
        }

        $service = $this->makeService();

        try {
            $service->upload($root['path'], $parent, $_FILES['file']);
            $this->flash('success', 'File uploaded successfully.');
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());
        }

        $this->redirectToBrowse($root['id'], $parent);
    }

    // -------------------------------------------------------------------------
    // GET /files/{rootId}/download — stream file download
    // -------------------------------------------------------------------------

    public function download(array $params = []): void
    {
        $root = $this->findRoot($params['rootId'] ?? '');

        if ($root === null) {
            http_response_code(404);
            echo '<h1>404 &mdash; Storage root not found</h1>';
            return;
        }

        $relativePath = $this->sanitizeRelativePath($_GET['path'] ?? '');
        $service      = $this->makeService();

        try {
            $abs      = $service->absolutePath($root['path'], $relativePath);
            $filename = basename($abs);
            $size     = filesize($abs);
        } catch (\RuntimeException $e) {
            http_response_code(404);
            echo '<h1>404 &mdash; ' . htmlspecialchars($e->getMessage()) . '</h1>';
            return;
        }

        // Detect MIME type for Content-Type.
        $mime = function_exists('mime_content_type') ? mime_content_type($abs) : 'application/octet-stream';
        if (!$mime) {
            $mime = 'application/octet-stream';
        }

        header('Content-Description: File Transfer');
        header('Content-Type: ' . $mime);
        header('Content-Disposition: attachment; filename="' . addslashes($filename) . '"');
        header('Content-Length: ' . $size);
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');

        readfile($abs);
        exit;
    }

    // -------------------------------------------------------------------------
    // POST /files/{rootId}/delete — delete file or empty directory
    // -------------------------------------------------------------------------

    public function delete(array $params = []): void
    {
        $root = $this->findRoot($params['rootId'] ?? '');

        if ($root === null) {
            http_response_code(404);
            echo '<h1>404 &mdash; Storage root not found</h1>';
            return;
        }

        $relativePath = $this->sanitizeRelativePath($_POST['path'] ?? '');
        $service      = $this->makeService();

        // Determine parent path for post-delete redirect.
        $parentPath = ltrim(dirname($relativePath), '/.');
        if ($parentPath === '' || $parentPath === '.') {
            $parentPath = '';
        }

        try {
            $service->delete($root['path'], $relativePath);
            $this->flash('success', 'Deleted successfully.');
        } catch (\RuntimeException $e) {
            $this->flash('error', $e->getMessage());
            $parentPath = $relativePath; // Stay on current path so flash is visible.
        }

        $this->redirectToBrowse($root['id'], $parentPath);
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Find a configured root by its ID.
     * Returns null if the root is not found or is disabled.
     */
    private function findRoot(string $rootId): ?array
    {
        if ($rootId === '') {
            return null;
        }

        $fmConfig = Config::load('filemanager');

        foreach ($fmConfig['roots'] ?? [] as $root) {
            if (($root['id'] ?? '') === $rootId && !empty($root['enabled'])) {
                return $root;
            }
        }

        return null;
    }

    /**
     * Sanitize a relative path from user input.
     * Strips leading slashes and trims whitespace.
     * The PathResolver performs the real safety check — this just normalizes input.
     */
    private function sanitizeRelativePath(string $path): string
    {
        return ltrim(trim($path), '/\\');
    }

    /**
     * Build the breadcrumb array for a given relative path.
     *
     * Returns an array of ['label' => string, 'url' => string|null] entries.
     * The last entry has url=null (current location, not clickable).
     */
    private function buildBreadcrumbs(array $root, string $relativePath): array
    {
        $crumbs = [
            ['label' => $root['label'], 'url' => '/files/' . rawurlencode($root['id'])],
        ];

        if ($relativePath === '') {
            // At root — make the root entry non-clickable.
            $crumbs[0]['url'] = null;
            return $crumbs;
        }

        $parts = explode('/', $relativePath);
        $built = '';

        foreach ($parts as $i => $part) {
            $built   = $built === '' ? $part : $built . '/' . $part;
            $isLast  = ($i === count($parts) - 1);
            $crumbs[] = [
                'label' => $part,
                'url'   => $isLast ? null : ('/files/' . rawurlencode($root['id']) . '?path=' . rawurlencode($built)),
            ];
        }

        return $crumbs;
    }

    /**
     * Instantiate the FileManagerService with a fresh PathResolver.
     */
    private function makeService(): FileManagerService
    {
        return new FileManagerService(new PathResolver());
    }

    /**
     * Redirect back to the browse view for a given root and path.
     */
    private function redirectToBrowse(string $rootId, string $relativePath): void
    {
        $url = '/files/' . rawurlencode($rootId);

        if ($relativePath !== '') {
            $url .= '?path=' . rawurlencode($relativePath);
        }

        header('Location: ' . $url);
        exit;
    }

    /**
     * Write a flash message to the session for the next request.
     */
    private function flash(string $type, string $message): void
    {
        $_SESSION['fm_flash'] = ['type' => $type, 'message' => $message];
    }

    /**
     * Read and clear the flash message from the session.
     */
    private function popFlash(): ?array
    {
        $flash = $_SESSION['fm_flash'] ?? null;
        unset($_SESSION['fm_flash']);
        return $flash;
    }
}
