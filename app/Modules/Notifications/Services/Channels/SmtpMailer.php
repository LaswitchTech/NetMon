<?php

namespace App\Modules\Notifications\Services\Channels;

/**
 * Minimal SMTP mailer — no external libraries required.
 *
 * Supports three transport modes:
 *   'tls'  → plain TCP connection upgraded via STARTTLS (typical port 587)
 *   'ssl'  → direct TLS socket from first byte, i.e. SMTPS (typical port 465)
 *   'none' → plain TCP, no encryption (development relay / internal MTA only)
 *
 * Authentication: AUTH LOGIN only (base64 username + password). Skipped when
 * smtp_user is an empty string (anonymous/relay use case).
 *
 * Throws \RuntimeException on any protocol or connection failure.
 * EmailChannel catches all \Throwable — this class may throw freely.
 *
 * The class is purposefully self-contained:
 *   - No NetMon-specific dependencies
 *   - No dependency injection
 *   - All state is local to send()
 */
class SmtpMailer
{
    private const TIMEOUT = 10;     // seconds per socket read/write
    private const READ_BUF = 1024;  // bytes per fgets call

    /**
     * Send a single email.
     *
     * @param array{
     *   smtp_host:    string,
     *   smtp_port:    int,
     *   smtp_user:    string,
     *   smtp_pass:    string,
     *   encryption:   string,
     *   from_address: string,
     *   from_name:    string
     * } $config
     * @param string $to       Recipient email address
     * @param string $subject  Email subject line
     * @param string $body     Plain-text message body
     *
     * @throws \RuntimeException on connection, protocol, or authentication failure
     */
    public function send(array $config, string $to, string $subject, string $body): void
    {
        $host       = $config['smtp_host'];
        $port       = (int) $config['smtp_port'];
        $user       = $config['smtp_user'];
        $pass       = $config['smtp_pass'];
        $encryption = strtolower($config['encryption'] ?? 'tls');
        $from       = $config['from_address'];
        $fromName   = $config['from_name'] ?? '';

        // For SMTPS (ssl), prefix the scheme so PHP's SSL socket wrapper activates.
        // For TLS (STARTTLS) and plain, start as a TCP socket.
        $address = ($encryption === 'ssl')
            ? "ssl://{$host}:{$port}"
            : "tcp://{$host}:{$port}";

        $errNo  = 0;
        $errStr = '';

        // SSL context: verify the server certificate.
        // Override verify_peer via config/local.php for self-signed dev servers.
        $ctx = stream_context_create([
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $socket = stream_socket_client(
            $address,
            $errNo,
            $errStr,
            self::TIMEOUT,
            STREAM_CLIENT_CONNECT,
            $ctx
        );

        if ($socket === false) {
            throw new \RuntimeException(
                "SMTP: connect to {$address} failed: {$errStr} (errno {$errNo})"
            );
        }

        stream_set_timeout($socket, self::TIMEOUT);

        try {
            // ── SMTP handshake ────────────────────────────────────────────────

            // Greeting: server introduces itself.
            $this->expect($socket, '220');

            // EHLO: declare our identity; get supported extensions.
            $ehloHost = gethostname() ?: 'localhost';
            $this->writeLine($socket, "EHLO {$ehloHost}");
            $ehloResp = $this->readResponse($socket);
            if (!$this->isSuccess($ehloResp)) {
                throw new \RuntimeException("SMTP EHLO failed: {$ehloResp}");
            }

            // ── STARTTLS upgrade (TLS mode only) ──────────────────────────────
            if ($encryption === 'tls') {
                $this->writeLine($socket, 'STARTTLS');
                $this->expect($socket, '220');

                $ok = stream_socket_enable_crypto(
                    $socket,
                    true,
                    STREAM_CRYPTO_METHOD_TLS_CLIENT
                );

                if ($ok !== true) {
                    throw new \RuntimeException('SMTP: STARTTLS upgrade failed.');
                }

                // RFC 3207 §4: re-issue EHLO over the newly encrypted channel.
                $this->writeLine($socket, "EHLO {$ehloHost}");
                $ehloResp = $this->readResponse($socket);
                if (!$this->isSuccess($ehloResp)) {
                    throw new \RuntimeException("SMTP EHLO (post-TLS) failed: {$ehloResp}");
                }
            }

            // ── AUTH LOGIN ────────────────────────────────────────────────────
            // Skip entirely when smtp_user is blank (anonymous relay path).
            if ($user !== '') {
                $this->writeLine($socket, 'AUTH LOGIN');
                $this->expect($socket, '334');          // "Username:"
                $this->writeLine($socket, base64_encode($user));
                $this->expect($socket, '334');          // "Password:"
                $this->writeLine($socket, base64_encode($pass));
                $this->expect($socket, '235');          // Authentication successful
            }

            // ── Envelope ─────────────────────────────────────────────────────
            $this->writeLine($socket, "MAIL FROM:<{$from}>");
            $this->expect($socket, '250');

            $this->writeLine($socket, "RCPT TO:<{$to}>");
            $this->expect($socket, '250');

            // ── Message ───────────────────────────────────────────────────────
            $this->writeLine($socket, 'DATA');
            $this->expect($socket, '354');

            // Headers
            $fromHeader = $fromName !== ''
                ? $this->encodeHeader($fromName) . ' <' . $from . '>'
                : $from;

            $headers  = 'From: ' . $fromHeader . "\r\n";
            $headers .= 'To: <' . $to . ">\r\n";
            $headers .= 'Subject: ' . $this->encodeHeader($subject) . "\r\n";
            $headers .= 'MIME-Version: 1.0' . "\r\n";
            $headers .= 'Content-Type: text/plain; charset=UTF-8' . "\r\n";
            $headers .= 'Content-Transfer-Encoding: 8bit' . "\r\n";
            $headers .= 'Date: ' . date('r') . "\r\n";
            $headers .= 'Message-ID: <' . uniqid('nm', true) . '@' . $ehloHost . ">\r\n";

            // RFC 5321 §4.5.2: dot-stuffing — lines starting with '.' need an extra '.'.
            $safeBody = preg_replace('/^\./m', '..', $body);

            // Write headers + blank line + body + end-of-data marker.
            fwrite($socket, $headers . "\r\n" . $safeBody . "\r\n.\r\n");
            $this->expect($socket, '250');

            // ── QUIT ──────────────────────────────────────────────────────────
            $this->writeLine($socket, 'QUIT');
            // Read (but do not fail on) the 221 goodbye — server may close first.
            $this->readResponse($socket);

        } finally {
            fclose($socket);
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function writeLine($socket, string $data): void
    {
        fwrite($socket, $data . "\r\n");
    }

    /**
     * Read a complete SMTP response (may be multi-line).
     *
     * Multi-line format:
     *   250-First line
     *   250-Second line
     *   250 Last line     ← the space at position 3 signals end of response
     *
     * Returns the full response string, trailing whitespace stripped.
     */
    private function readResponse($socket): string
    {
        $response = '';

        while (($line = fgets($socket, self::READ_BUF)) !== false && $line !== '') {
            $response .= $line;
            // Position 3 is '-' in continuation lines and ' ' in the final line.
            if (strlen($line) >= 4 && $line[3] === ' ') {
                break;
            }
        }

        return rtrim($response);
    }

    /**
     * Read a response and throw if it does not start with the expected code.
     */
    private function expect($socket, string $code): string
    {
        $resp = $this->readResponse($socket);

        if (!str_starts_with($resp, $code)) {
            $preview = substr($resp, 0, 100);
            throw new \RuntimeException(
                "SMTP: expected {$code}, got: {$preview}"
            );
        }

        return $resp;
    }

    /**
     * Returns true if the response code is in the 2xx or 3xx range (positive).
     */
    private function isSuccess(string $response): bool
    {
        return $response !== '' && in_array($response[0], ['2', '3'], true);
    }

    /**
     * Encode a header value as RFC 2047 UTF-8 base64 if it contains non-ASCII.
     * ASCII-safe values (e.g. plain English subject lines) are returned as-is.
     */
    private function encodeHeader(string $value): string
    {
        if ($value === '' || !preg_match('/[^\x20-\x7E]/', $value)) {
            return $value;
        }

        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }
}
