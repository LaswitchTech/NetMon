<?php

namespace App\Core\Installer;

/**
 * Checks that required directories exist and are writable.
 *
 * If a directory does not exist, an attempt is made to create it.
 * This allows installers to work on fresh deployments where the
 * directories have not yet been created.
 *
 * Result shape:
 *   [
 *     'ok'    => bool,
 *     'paths' => [
 *       [
 *         'label'  => string,       // short human-readable label
 *         'path'   => string,       // absolute path checked
 *         'status' => 'ok'|'error',
 *         'fix'    => string|null,  // actionable fix hint on failure
 *       ],
 *       ...
 *     ],
 *   ]
 */
class DirectoryChecker
{
    /**
     * @param array<string, string> $paths  label => absolute path
     */
    public function __construct(private readonly array $paths) {}

    // -------------------------------------------------------------------------

    public function check(): array
    {
        $results = [];
        $ok      = true;

        foreach ($this->paths as $label => $path) {
            [$status, $fix] = $this->evaluate($path);

            $results[] = [
                'label'  => $label,
                'path'   => $path,
                'status' => $status,
                'fix'    => $fix,
            ];

            if ($status === 'error') {
                $ok = false;
            }
        }

        return ['ok' => $ok, 'paths' => $results];
    }

    // -------------------------------------------------------------------------

    /**
     * @return array{string, string|null}  [status, fix]
     */
    private function evaluate(string $path): array
    {
        if (is_dir($path)) {
            if (is_writable($path)) {
                return ['ok', null];
            }

            return [
                'error',
                "Directory exists but is not writable. Run: chmod 775 {$path}",
            ];
        }

        // Directory absent — attempt to create it
        if (@mkdir($path, 0775, true)) {
            return ['ok', null];
        }

        return [
            'error',
            "Directory does not exist and could not be created. Run: mkdir -p {$path} && chmod 775 {$path}",
        ];
    }
}
