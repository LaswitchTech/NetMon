<?php

namespace App\Core;

class ErrorHandler
{
    private Logger $logger;
    private bool   $debug;

    public function __construct(Logger $logger, bool $debug = false)
    {
        $this->logger = $logger;
        $this->debug  = $debug;
    }

    /**
     * Install exception, error, and shutdown handlers.
     */
    public function register(): void
    {
        set_exception_handler([$this, 'handleException']);
        set_error_handler([$this, 'handleError']);
        register_shutdown_function([$this, 'handleShutdown']);
    }

    // -------------------------------------------------------------------------
    // Exception handler
    // -------------------------------------------------------------------------

    public function handleException(\Throwable $e): void
    {
        $status  = $this->statusFromException($e);
        $message = $e->getMessage();

        $this->logger->error($message, [
            'exception' => get_class($e),
            'file'      => $e->getFile(),
            'line'      => $e->getLine(),
            'trace'     => $this->debug ? $e->getTraceAsString() : null,
        ]);

        $this->respond($status, $message, $e);
    }

    // -------------------------------------------------------------------------
    // Error handler — converts PHP errors to ErrorException where appropriate
    // -------------------------------------------------------------------------

    public function handleError(int $severity, string $message, string $file, int $line): bool
    {
        // Respect the @ operator
        if (!(error_reporting() & $severity)) {
            return false;
        }

        // Convert recoverable errors into exceptions
        $throw = E_ERROR | E_WARNING | E_USER_ERROR | E_USER_WARNING | E_RECOVERABLE_ERROR;

        if ($severity & $throw) {
            throw new \ErrorException($message, 0, $severity, $file, $line);
        }

        // Log non-thrown notices / deprecations but do not interrupt execution
        $this->logger->warning($message, [
            'severity' => $severity,
            'file'     => $file,
            'line'     => $line,
        ]);

        return true;
    }

    // -------------------------------------------------------------------------
    // Shutdown handler — catches fatal errors missed by set_error_handler
    // -------------------------------------------------------------------------

    public function handleShutdown(): void
    {
        $err = error_get_last();

        $fatals = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR;

        if ($err && ($err['type'] & $fatals)) {
            $this->logger->error('Fatal error: ' . $err['message'], [
                'file' => $err['file'],
                'line' => $err['line'],
            ]);

            // Headers may already be sent on a fatal during output — best effort
            if (!headers_sent()) {
                $this->respond(500, 'Internal Server Error', null);
            }
        }
    }

    // -------------------------------------------------------------------------
    // Helpers
    // -------------------------------------------------------------------------

    /**
     * Map exception types to HTTP status codes.
     */
    private function statusFromException(\Throwable $e): int
    {
        return match (true) {
            $e instanceof \InvalidArgumentException  => 400,
            $e instanceof \OverflowException         => 400,
            $e instanceof \UnderflowException        => 400,
            $e instanceof \RangeException            => 400,
            $e instanceof \OutOfRangeException       => 400,
            $e instanceof \LengthException           => 400,
            $e instanceof \DomainException           => 422,
            $e instanceof \OutOfBoundsException      => 404,
            $e instanceof \UnexpectedValueException  => 422,
            default                                  => 500,
        };
    }

    /**
     * Decide whether this request expects a JSON response.
     * Criteria: Accept header contains application/json, or URI starts with /api/.
     */
    private function isApiRequest(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $uri    = $_SERVER['REQUEST_URI'] ?? '';

        return str_contains($accept, 'application/json')
            || str_starts_with($uri, '/api/');
    }

    /**
     * Send the appropriate response format and terminate.
     */
    private function respond(int $status, string $message, ?\Throwable $e): void
    {
        if (!headers_sent()) {
            http_response_code($status);
        }

        if ($this->isApiRequest()) {
            $this->respondJson($status, $message, $e);
        } else {
            $this->respondHtml($status, $message, $e);
        }
    }

    private function respondJson(int $status, string $message, ?\Throwable $e): void
    {
        if (!headers_sent()) {
            header('Content-Type: application/json');
        }

        $body = [
            'error'  => true,
            'status' => $status,
            'message' => $this->debug ? $message : $this->genericMessage($status),
        ];

        if ($this->debug && $e !== null) {
            $body['exception'] = get_class($e);
            $body['file']      = $e->getFile();
            $body['line']      = $e->getLine();
            $body['trace']     = explode("\n", $e->getTraceAsString());
        }

        echo json_encode($body);
    }

    private function respondHtml(int $status, string $message, ?\Throwable $e): void
    {
        if (!headers_sent()) {
            header('Content-Type: text/html; charset=utf-8');
        }

        $safe    = htmlspecialchars($this->debug ? $message : $this->genericMessage($status), ENT_QUOTES, 'UTF-8');
        $heading = htmlspecialchars($this->genericMessage($status), ENT_QUOTES, 'UTF-8');

        $trace = '';
        if ($this->debug && $e !== null) {
            $traceText = htmlspecialchars($e->getTraceAsString(), ENT_QUOTES, 'UTF-8');
            $fileText  = htmlspecialchars($e->getFile() . ':' . $e->getLine(), ENT_QUOTES, 'UTF-8');
            $trace = <<<HTML
                <p><strong>Location:</strong> <code>{$fileText}</code></p>
                <pre>{$traceText}</pre>
            HTML;
        }

        echo <<<HTML
        <!DOCTYPE html>
        <html lang="en">
        <head><meta charset="utf-8"><title>{$status} {$heading}</title>
        <style>body{font-family:sans-serif;padding:2rem;color:#333}pre{background:#f4f4f4;padding:1rem;overflow:auto}</style>
        </head>
        <body>
          <h1>{$status} — {$heading}</h1>
          <p>{$safe}</p>
          {$trace}
        </body>
        </html>
        HTML;
    }

    private function genericMessage(int $status): string
    {
        return match ($status) {
            400     => 'Bad Request',
            401     => 'Unauthorized',
            403     => 'Forbidden',
            404     => 'Not Found',
            422     => 'Unprocessable Entity',
            500     => 'Internal Server Error',
            default => 'Error',
        };
    }
}
