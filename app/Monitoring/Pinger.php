<?php

namespace App\Monitoring;

/**
 * Device reachability check via the system ping command.
 *
 * Uses exec() to invoke the OS ping binary. This approach requires:
 *   - exec() to be available (not listed in php.ini disable_functions)
 *   - The ping binary to be in $PATH (standard on Linux, macOS, Windows)
 *
 * On most Linux/macOS systems, ping is setuid root and works without
 * elevated privileges from a CLI process.
 *
 * If exec() is unavailable, check() returns status='error' with an
 * explanatory message rather than throwing — callers should log this
 * and continue to the next device.
 *
 * Platform notes:
 *   Linux  — ping -c 1 -W <seconds>   (timeout in whole seconds)
 *   macOS  — ping -c 1 -W <ms>        (timeout in milliseconds)
 *   Windows — ping -n 1 -w <ms>
 *   IPv6   — prefers ping6; falls back to ping with -6 flag if ping6 absent
 */
class Pinger
{
    /**
     * @param  string $address  IPv4 address, IPv6 address, or hostname
     * @param  int    $timeoutSeconds  Maximum seconds to wait for a response
     * @return array{
     *   status:     'online'|'offline'|'timeout'|'error',
     *   latency_ms: int|null,
     *   message:    string|null
     * }
     */
    public function check(string $address, int $timeoutSeconds = 2): array
    {
        if (!function_exists('exec')) {
            return [
                'status'     => 'error',
                'latency_ms' => null,
                'message'    => 'exec() is disabled in php.ini; cannot run ping',
            ];
        }

        $address = trim($address);

        if ($address === '') {
            return [
                'status'     => 'error',
                'latency_ms' => null,
                'message'    => 'No target address provided',
            ];
        }

        $isIpv6 = str_contains($address, ':');
        $cmd    = $this->buildCommand($address, $isIpv6, $timeoutSeconds);

        $output   = [];
        $exitCode = 0;
        $start    = microtime(true);

        exec($cmd, $output, $exitCode);

        $elapsed = microtime(true) - $start;

        if ($exitCode === 0) {
            $outputStr = implode("\n", $output);
            $rtt       = $this->extractRtt($outputStr);

            return [
                'status'     => 'online',
                'latency_ms' => $rtt ?? (int) round($elapsed * 1000),
                'message'    => null,
            ];
        }

        // Distinguish timeout from hard failure where possible.
        // Most ping implementations exit 1 for no-response (timeout) and 2 for
        // network-level errors. We normalize both as 'offline' and let the
        // monitoring runner decide the device status.
        return [
            'status'     => 'offline',
            'latency_ms' => null,
            'message'    => 'Host did not respond to ping',
        ];
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Build a platform-appropriate ping command string.
     * Output is captured via 2>&1 so errors are not printed to the runner's stderr.
     */
    private function buildCommand(string $address, bool $isIpv6, int $timeoutSeconds): string
    {
        $safe = escapeshellarg($address);
        $os   = PHP_OS_FAMILY;

        if ($os === 'Windows') {
            // -n 1: one packet; -w: wait in ms
            return sprintf('ping -n 1 -w %d %s 2>&1', $timeoutSeconds * 1000, $safe);
        }

        if ($isIpv6) {
            return $this->buildIpv6Command($address, $timeoutSeconds);
        }

        if ($os === 'Darwin') {
            // macOS: -W accepts milliseconds
            return sprintf('ping -c 1 -W %d %s 2>&1', $timeoutSeconds * 1000, $safe);
        }

        // Linux and other POSIX: -W accepts seconds
        return sprintf('ping -c 1 -W %d %s 2>&1', $timeoutSeconds, $safe);
    }

    /**
     * Build an IPv6 ping command.
     *
     * Preference order:
     *   1. ping6 (explicitly IPv6, available on most Linux/BSD/macOS)
     *   2. ping -6 (GNU inetutils, Linux)
     *   3. ping with the raw IPv6 address (falls through on platforms where
     *      ping auto-detects the address family)
     */
    private function buildIpv6Command(string $address, int $timeoutSeconds): string
    {
        $safe = escapeshellarg($address);
        $os   = PHP_OS_FAMILY;

        // Check if ping6 exists
        exec('which ping6 2>/dev/null', $out, $rc);
        if ($rc === 0 && !empty($out)) {
            if ($os === 'Darwin') {
                return sprintf('ping6 -c 1 -W %d %s 2>&1', $timeoutSeconds * 1000, $safe);
            }
            return sprintf('ping6 -c 1 -W %d %s 2>&1', $timeoutSeconds, $safe);
        }

        // Fallback: ping -6 (GNU ping)
        if ($os === 'Darwin') {
            return sprintf('ping -6 -c 1 -W %d %s 2>&1', $timeoutSeconds * 1000, $safe);
        }
        return sprintf('ping -6 -c 1 -W %d %s 2>&1', $timeoutSeconds, $safe);
    }

    /**
     * Extract the average RTT from ping output in milliseconds.
     *
     * Handles the two common summary line formats:
     *   Linux:  "rtt min/avg/max/mdev = 0.123/0.456/0.789/0.000 ms"
     *   macOS:  "round-trip min/avg/max/stddev = 0.123/0.456/0.789/0.000 ms"
     *
     * Returns null if the pattern is not found (e.g. offline or unrecognised format).
     */
    private function extractRtt(string $output): ?int
    {
        if (preg_match('/[\d.]+\/([\d.]+)\/[\d.]+\/[\d.]+\s*ms/i', $output, $m)) {
            return (int) round((float) $m[1]);
        }

        return null;
    }
}
