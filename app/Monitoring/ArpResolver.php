<?php

namespace App\Monitoring;

/**
 * Best-effort ARP cache lookup.
 *
 * Reads the local ARP table via the system `arp` command and extracts the MAC
 * address for a given IP. This only works if the target IP is already in the
 * host's ARP cache — i.e., the host has recently communicated with the target.
 * Running a ping sweep immediately before calling resolve() maximises cache hits.
 *
 * Supported platforms: Linux, macOS.
 * Command used: arp -n <ip>   (numeric output; suppresses reverse DNS on both platforms)
 *
 * Design:
 *   - Returns null on any failure (exec disabled, IP not in cache, parse error)
 *   - Never throws
 *   - IP is validated before being passed to the shell (no command injection)
 */
class ArpResolver
{
    /**
     * Look up the MAC address for a given IP in the local ARP cache.
     *
     * @param  string $ip  IPv4 address to look up
     * @return string|null  Normalised lower-case MAC (e.g. "00:11:22:33:44:55"), or null
     */
    public function resolve(string $ip): ?string
    {
        // Reject anything that is not a valid IPv4/IPv6 address.
        // This prevents shell injection if a malformed IP somehow reaches this method.
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            return null;
        }

        // exec() may be disabled in restricted PHP environments.
        if (!function_exists('exec')) {
            return null;
        }

        $output   = [];
        $exitCode = 0;

        // arp -n suppresses name resolution on both Linux and macOS.
        // escapeshellarg() is belt-and-suspenders after the FILTER_VALIDATE_IP check.
        @exec('arp -n ' . escapeshellarg($ip) . ' 2>/dev/null', $output, $exitCode);

        if (empty($output)) {
            return null;
        }

        $text = implode("\n", $output);

        // Match MAC in colon-separated (Linux/macOS) or dash-separated format.
        // Pattern: six groups of two hex digits separated by : or -
        if (preg_match('/([0-9a-f]{2}(?:[:\-][0-9a-f]{2}){5})/i', $text, $matches)) {
            return strtolower($matches[1]);
        }

        return null;
    }
}
