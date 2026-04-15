<?php

namespace App\Monitoring;

/**
 * IPv4 subnet scanner — synchronous ICMP ping sweep.
 *
 * Iterates every usable host address in a CIDR range and uses Pinger to check
 * reachability. Returns only the addresses that responded.
 *
 * Limitations (intentional for Phase 1 simplicity):
 *   - IPv4 only. IPv6 subnets are not supported.
 *   - Synchronous only — each host is checked one at a time. For a /24 subnet
 *     with a 1-second timeout, worst-case scan time is ~254 seconds. In
 *     practice, responsive hosts reply in milliseconds and only silent ones
 *     consume the full timeout.
 *   - No ARP or reverse DNS — mac_address and hostname are not populated.
 *     These will be added in a later phase (nmap integration or ARP scan).
 *   - No nmap. The scanner uses exec(ping) via the existing Pinger class.
 *
 * Safety:
 *   - Subnets with a prefix shorter than /16 (> 65 534 hosts) are rejected to
 *     prevent accidental long-running scans of large address spaces.
 *   - The network address (first) and broadcast address (last) are always
 *     excluded from the scan.
 */
class SubnetScanner
{
    /** Maximum allowed prefix length (inclusive). Subnets /15 and shorter are rejected. */
    private const MIN_PREFIX = 16;

    private Pinger $pinger;

    public function __construct(?Pinger $pinger = null)
    {
        $this->pinger = $pinger ?? new Pinger();
    }

    // -------------------------------------------------------------------------
    // Public API
    // -------------------------------------------------------------------------

    /**
     * Scan all usable host addresses in a CIDR subnet.
     *
     * @param  string   $cidr            IPv4 CIDR notation, e.g. '192.168.1.0/24'
     * @param  int      $timeoutSeconds  Per-host ping timeout (default 1 s)
     * @param  callable|null $onProgress  Optional callback(string $ip, bool $responded)
     *                                    called after each host is checked.
     *                                    Useful for streaming verbose output in the CLI.
     * @return string[]  IP addresses that responded, in scan order
     * @throws \InvalidArgumentException  On invalid CIDR notation or oversized subnet
     */
    public function scan(string $cidr, int $timeoutSeconds = 1, ?callable $onProgress = null): array
    {
        [$firstLong, $lastLong] = $this->parseRange($cidr);

        $responsive = [];

        for ($ip = $firstLong; $ip <= $lastLong; $ip++) {
            $address = long2ip($ip);
            $result  = $this->pinger->check($address, $timeoutSeconds);
            $up      = $result['status'] === 'online';

            if ($up) {
                $responsive[] = $address;
            }

            if ($onProgress !== null) {
                $onProgress($address, $up, $result['latency_ms']);
            }
        }

        return $responsive;
    }

    /**
     * Return the number of usable host addresses in a CIDR subnet.
     *
     * Useful for pre-scan output ("Scanning 254 hosts…").
     *
     * @param  string $cidr
     * @return int
     * @throws \InvalidArgumentException
     */
    public function hostCount(string $cidr): int
    {
        [$first, $last] = $this->parseRange($cidr);
        return max(0, $last - $first + 1);
    }

    // -------------------------------------------------------------------------
    // Internals
    // -------------------------------------------------------------------------

    /**
     * Parse a CIDR string into [firstHostLong, lastHostLong].
     *
     * Network address (.0) and broadcast address (.255) are excluded.
     * A /32 returns a single address (the host itself).
     * A /31 returns both addresses (point-to-point link, RFC 3021).
     *
     * @param  string $cidr
     * @return array{int, int}  [first, last] as unsigned long integers
     * @throws \InvalidArgumentException
     */
    private function parseRange(string $cidr): array
    {
        if (!str_contains($cidr, '/')) {
            // Bare IP — treat as /32 (single host)
            $long = ip2long($cidr);
            if ($long === false) {
                throw new \InvalidArgumentException("Invalid IP address: '{$cidr}'");
            }
            return [$long, $long];
        }

        $parts = explode('/', $cidr, 2);
        if (count($parts) !== 2) {
            throw new \InvalidArgumentException("Invalid CIDR notation: '{$cidr}'");
        }

        [$network, $prefixStr] = $parts;

        if (!is_numeric($prefixStr)) {
            throw new \InvalidArgumentException("Non-numeric prefix in CIDR: '{$cidr}'");
        }

        $prefix = (int) $prefixStr;

        if ($prefix < 0 || $prefix > 32) {
            throw new \InvalidArgumentException("CIDR prefix must be 0–32, got {$prefix}");
        }

        if ($prefix < self::MIN_PREFIX) {
            $maxHosts = (1 << (32 - self::MIN_PREFIX)) - 2;
            throw new \InvalidArgumentException(
                "Subnet /{$prefix} is too large (max allowed is /" . self::MIN_PREFIX
                . ", covering up to {$maxHosts} hosts). "
                . "Split the subnet or adjust the limit in SubnetScanner::MIN_PREFIX."
            );
        }

        $networkLong = ip2long($network);
        if ($networkLong === false) {
            throw new \InvalidArgumentException("Invalid network address in CIDR: '{$network}'");
        }

        // Align to network boundary (zero out host bits)
        $hostBits    = 32 - $prefix;
        $networkLong = ($networkLong >> $hostBits) << $hostBits;

        if ($prefix === 32) {
            // Single host — no network/broadcast
            return [$networkLong, $networkLong];
        }

        if ($prefix === 31) {
            // RFC 3021 point-to-point — no network/broadcast convention
            return [$networkLong, $networkLong + 1];
        }

        $size  = 1 << $hostBits;
        $first = $networkLong + 1;       // skip network address
        $last  = $networkLong + $size - 2; // skip broadcast

        return [$first, $last];
    }
}
