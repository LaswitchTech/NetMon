<?php

/**
 * NetMon — Discovery Runner
 *
 * Scans enabled discovery_jobs via ICMP ping sweep and stores findings in
 * discovery_findings. Findings are matched against known device addresses
 * and marked accordingly. No devices are ever created or modified automatically.
 *
 * Workflow per job:
 *   1. Load the subnet from discovery_jobs
 *   2. Ping-sweep every usable host address in the subnet
 *   3. For each responding IP:
 *        a. Attempt reverse DNS (gethostbyaddr) — stores hostname if resolved
 *        b. Attempt ARP lookup (ArpResolver) — stores MAC if in local ARP cache
 *        c. Look up ip_address in device_addresses for matching
 *        d. If found:  status = 'matched',  matched_device_id = device.id
 *           If not:    status = 'pending',  matched_device_id = NULL
 *        e. saveFinding() — upserts: creates on first sight, updates on re-scan
 *           Existing hostname/MAC are preserved if the new scan cannot resolve them
 *   4. Update discovery_jobs.last_run_at
 *
 * Usage:
 *   php scripts/discover.php              # run all enabled jobs
 *   php scripts/discover.php --verbose    # show per-host detail
 *   php scripts/discover.php --dry-run    # parse jobs but do not ping or write
 *   php scripts/discover.php --job=<id>   # run a single job by ID
 *
 * Schedule with cron (e.g. hourly):
 *   0 * * * * php /path/to/netmon/scripts/discover.php >> /path/to/netmon/storage/logs/discovery.log 2>&1
 *
 * Safety:
 *   - NEVER creates devices automatically
 *   - NEVER modifies device_addresses, device_interfaces, or devices
 *   - Findings with status 'pending' require manual operator review
 */

declare(strict_types=1);

// ---------------------------------------------------------------------------
// Autoloader
// ---------------------------------------------------------------------------
spl_autoload_register(function (string $class): void {
    $prefix = 'App\\';
    $base   = __DIR__ . '/../app/';

    if (strncmp($class, $prefix, strlen($prefix)) !== 0) {
        return;
    }

    $relative = substr($class, strlen($prefix));
    $file     = $base . str_replace('\\', '/', $relative) . '.php';

    if (file_exists($file)) {
        require $file;
    }
});

// ---------------------------------------------------------------------------
// Bootstrap
// ---------------------------------------------------------------------------
use App\Core\Config;
use App\Core\Env;
use App\Core\SQLiteDriver;
use App\Models\DiscoveryRepository;
use App\Monitoring\ArpResolver;
use App\Monitoring\SubnetScanner;

Env::load(__DIR__ . '/../.env');

$dbConfig = Config::load('database');

if ($dbConfig['driver'] === 'sqlite') {
    $db = new SQLiteDriver($dbConfig['sqlite']['path']);
} else {
    fwrite(STDERR, "Unsupported database driver: {$dbConfig['driver']}\n");
    exit(1);
}

// ---------------------------------------------------------------------------
// Parse flags
// ---------------------------------------------------------------------------
$args    = array_slice($argv, 1);
$verbose = in_array('--verbose', $args, true) || in_array('-v', $args, true);
$dryRun  = in_array('--dry-run', $args, true);

// --job=<id> to target a single job
$onlyJobId = null;
foreach ($args as $arg) {
    if (preg_match('/^--job=(\d+)$/', $arg, $m)) {
        $onlyJobId = (int) $m[1];
        break;
    }
}

// ---------------------------------------------------------------------------
// Run
// ---------------------------------------------------------------------------
$repo        = new DiscoveryRepository($db);
$scanner     = new SubnetScanner();
$arpResolver = new ArpResolver();

$runStart  = microtime(true);
$timestamp = date('Y-m-d H:i:s');

echo "NetMon Discovery — {$timestamp}\n";
echo str_repeat('-', 50) . "\n";

if ($dryRun) {
    echo "[DRY RUN] No pings will be sent and no findings will be written.\n";
}

// Load jobs
$jobs = $repo->findEnabledJobs();

if ($onlyJobId !== null) {
    $jobs = array_filter($jobs, fn($j) => (int) $j['id'] === $onlyJobId);
    $jobs = array_values($jobs);

    if (empty($jobs)) {
        fwrite(STDERR, "No enabled job found with id={$onlyJobId}\n");
        exit(1);
    }
}

if (empty($jobs)) {
    echo "No enabled discovery jobs configured.\n";
    exit(0);
}

// Counts for final summary
$totalFound   = 0;
$totalMatched = 0;
$totalPending = 0;
$totalUpdated = 0;

foreach ($jobs as $job) {
    $jobId     = (int) $job['id'];
    $jobName   = $job['name'];
    $subnet    = $job['subnet'];

    echo "\nJob: {$jobName} — {$subnet}\n";

    // Validate subnet and report host count before scanning
    try {
        $hostCount = $scanner->hostCount($subnet);
    } catch (\InvalidArgumentException $e) {
        echo "  [SKIP] Invalid subnet: " . $e->getMessage() . "\n";
        continue;
    }

    echo "  Scanning {$hostCount} host(s)...\n";

    if ($dryRun) {
        echo "  [DRY RUN] Scan skipped.\n";
        continue;
    }

    $jobFound   = 0;
    $jobMatched = 0;
    $jobPending = 0;

    // Progress callback: called after each host is checked.
    $onProgress = function (string $ip, bool $responded, ?int $latencyMs) use (
        $verbose, $repo, $arpResolver, $jobId, &$jobFound, &$jobMatched, &$jobPending, &$totalUpdated
    ): void {
        if (!$responded) {
            if ($verbose) {
                echo "  [--]  {$ip}\n";
            }
            return;
        }

        // ── Enrichment (best-effort) ─────────────────────────────────────
        // Reverse DNS: gethostbyaddr returns the IP itself on failure.
        $resolved = gethostbyaddr($ip);
        $hostname = ($resolved !== false && $resolved !== $ip) ? $resolved : null;

        // ARP cache lookup: null if not in cache or exec unavailable.
        $mac = $arpResolver->resolve($ip);

        // ── Matching ─────────────────────────────────────────────────────
        $match           = $repo->findDeviceByAddress($ip);
        $status          = $match !== null ? 'matched' : 'pending';
        $matchedDeviceId = $match !== null ? (int) $match['id'] : null;

        $repo->saveFinding([
            'job_id'            => $jobId,
            'ip_address'        => $ip,
            'mac_address'       => $mac,
            'hostname'          => $hostname,
            'status'            => $status,
            'matched_device_id' => $matchedDeviceId,
        ]);

        $jobFound++;
        $totalUpdated++;

        if ($status === 'matched') {
            $jobMatched++;
            $label  = "[MATCH]";
            $detail = $match['name'];
        } else {
            $jobPending++;
            $label  = "[NEW]  ";
            $detail = 'no device';
        }

        $latency = $latencyMs !== null ? "{$latencyMs} ms" : '—';

        // Build enrichment suffix: hostname and/or MAC if available
        $enrichment = [];
        if ($hostname !== null) {
            $enrichment[] = $hostname;
        }
        if ($mac !== null) {
            $enrichment[] = $mac;
        }
        $enrichmentStr = !empty($enrichment) ? ('   ' . implode('   ', $enrichment)) : '';

        echo "  {$label} " . str_pad($ip, 18) . str_pad($latency, 8) . " {$detail}{$enrichmentStr}\n";
    };

    // Run the sweep
    $scanner->scan($subnet, timeoutSeconds: 1, onProgress: $onProgress);

    // Stamp the job
    $repo->updateJobLastRun($jobId, $timestamp);

    echo "  Found {$jobFound} host(s): {$jobMatched} matched, {$jobPending} pending.\n";

    $totalFound   += $jobFound;
    $totalMatched += $jobMatched;
    $totalPending += $jobPending;
}

// ---------------------------------------------------------------------------
// Summary
// ---------------------------------------------------------------------------
if (!$dryRun) {
    $elapsed = round(microtime(true) - $runStart, 2);

    echo "\n" . str_repeat('-', 50) . "\n";
    echo "── Discovery Summary\n";
    echo "Hosts found:   {$totalFound}\n";
    echo "  Matched:     {$totalMatched}  (IP already known to a device)\n";
    echo "  Pending:     {$totalPending}  (new — review at /discovery)\n";
    echo "Elapsed:       {$elapsed}s\n";
}

exit(0);
