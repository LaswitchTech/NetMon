<?php

namespace App\Core;

class Logger
{
    private string $dir;

    public function __construct(string $dir)
    {
        $this->dir = rtrim($dir, '/');
    }

    public function error(string $message, array $context = []): void
    {
        $this->write('ERROR', $message, $context);
    }

    public function warning(string $message, array $context = []): void
    {
        $this->write('WARNING', $message, $context);
    }

    public function info(string $message, array $context = []): void
    {
        $this->write('INFO', $message, $context);
    }

    private function write(string $level, string $message, array $context): void
    {
        $file = $this->dir . '/app-' . date('Y-m-d') . '.log';
        $ts   = date('Y-m-d H:i:s');
        $line = "[{$ts}] [{$level}] {$message}";

        if (!empty($context)) {
            $line .= ' ' . json_encode($context);
        }

        $line .= PHP_EOL;

        // Silently fail if the log directory is not writable to avoid
        // triggering a recursive error-handler loop.
        @file_put_contents($file, $line, FILE_APPEND | LOCK_EX);
    }
}
