<?php

namespace App\Monitoring;

/**
 * Service-level TCP connectivity check.
 *
 * Attempts to open a TCP connection to the given host and port using
 * PHP's fsockopen(). No exec() or elevated privileges are required.
 *
 * Status values returned:
 *   'up'    — Connection established and closed cleanly
 *   'down'  — Connection refused, timed out, or host unreachable
 *   'error' — Check could not be attempted (invalid address or port)
 *
 * The latency reported is the wall-clock time from the start of the
 * fsockopen() call to the moment the connection is established. It
 * reflects TCP handshake time, not application-level response time.
 *
 * Timeout notes:
 *   fsockopen() accepts a float timeout in seconds. A value of 3.0 is
 *   the default — enough for LAN targets while avoiding long stalls
 *   when the host is unreachable (as opposed to refusing the connection,
 *   which fails immediately with ECONNREFUSED).
 *
 *   For hosts behind firewalls that silently drop packets rather than
 *   resetting the connection, the check will take the full timeout.
 *   Consider reducing $timeoutSeconds for LAN-only environments.
 */
class TcpChecker
{
    /**
     * Attempt a TCP connection to address:port.
     *
     * @param  string $address         IPv4 address, IPv6 address, or hostname
     * @param  int    $port            TCP port (1–65535)
     * @param  int    $timeoutSeconds  Maximum seconds to wait for the connection
     * @return array{
     *   status:     'up'|'down'|'error',
     *   latency_ms: int|null,
     *   message:    string|null
     * }
     */
    public function check(string $address, int $port, int $timeoutSeconds = 3): array
    {
        $address = trim($address);

        if ($address === '') {
            return [
                'status'     => 'error',
                'latency_ms' => null,
                'message'    => 'No target address provided',
            ];
        }

        if ($port < 1 || $port > 65535) {
            return [
                'status'     => 'error',
                'latency_ms' => null,
                'message'    => "Invalid port: {$port}",
            ];
        }

        $start      = microtime(true);
        $connection = @fsockopen($address, $port, $errno, $errstr, (float) $timeoutSeconds);
        $latencyMs  = (int) round((microtime(true) - $start) * 1000);

        if ($connection === false) {
            // A latency at or near the full timeout indicates a filtered port
            // (packet dropped rather than RST returned). Under $timeoutSeconds
            // indicates an immediate refusal or network error.
            $isTimeout = $latencyMs >= ($timeoutSeconds * 1000 - 200);
            $message   = $isTimeout
                ? 'Connection timed out'
                : (trim($errstr) ?: 'Connection refused');

            return [
                'status'     => 'down',
                'latency_ms' => null,
                'message'    => $message,
            ];
        }

        fclose($connection);

        return [
            'status'     => 'up',
            'latency_ms' => $latencyMs,
            'message'    => null,
        ];
    }
}
