<?php

namespace App\Core\Installer;

/**
 * Manages the two-signal installation lock.
 *
 * An application is considered installed only when BOTH of the following
 * are true:
 *   1. /storage/installed.lock exists on disk.
 *   2. APP_INSTALLED=true is set in the environment (.env).
 *
 * Requiring both signals provides defense in depth: a stray lock file
 * cannot block reinstallation if the .env flag disagrees, and vice versa.
 *
 * To allow reinstallation, both signals must be cleared manually:
 *   - delete /storage/installed.lock
 *   - set APP_INSTALLED=false in .env
 */
class InstallLock
{
    private string $lockFile;

    /**
     * @param string $storagePath  Absolute path to the storage directory
     *                             (e.g. __DIR__ . '/../storage')
     */
    public function __construct(string $storagePath)
    {
        $this->lockFile = rtrim($storagePath, DIRECTORY_SEPARATOR)
                        . DIRECTORY_SEPARATOR
                        . 'installed.lock';
    }

    /**
     * Returns true only when both lock signals are present:
     * the lock file exists AND APP_INSTALLED=true in the environment.
     */
    public function isInstalled(): bool
    {
        return file_exists($this->lockFile)
            && $this->envInstalledFlag();
    }

    /**
     * Write the lock file. Caller is responsible for also updating .env.
     *
     * @throws \RuntimeException if the file cannot be written.
     */
    public function write(): void
    {
        $result = file_put_contents($this->lockFile, date('Y-m-d H:i:s') . PHP_EOL);

        if ($result === false) {
            throw new \RuntimeException(
                "Could not write install lock: {$this->lockFile}"
            );
        }
    }

    /**
     * Remove the lock file. Caller is responsible for also updating .env.
     */
    public function clear(): void
    {
        if (file_exists($this->lockFile)) {
            unlink($this->lockFile);
        }
    }

    /**
     * Return the absolute path of the lock file (for diagnostics).
     */
    public function lockFilePath(): string
    {
        return $this->lockFile;
    }

    // -------------------------------------------------------------------------

    private function envInstalledFlag(): bool
    {
        $val = getenv('APP_INSTALLED');

        if ($val === false) {
            return false;
        }

        return in_array(strtolower($val), ['true', '1', 'yes', 'on'], true);
    }
}
