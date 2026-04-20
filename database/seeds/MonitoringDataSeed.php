<?php

use App\Core\DatabaseInterface;

/**
 * Seeds realistic monitoring history for development and demonstration.
 *
 * Creates:
 *   - device_checks        : 48 hours of 5-minute reachability checks per device
 *   - service_checks       : 24 hours of 5-minute TCP checks per monitored service
 *   - alerts               : open and resolved stateful alerts with realistic lifecycles
 *   - notification_history : email dispatch records linked to the seeded alerts
 *
 * Scenario summary:
 *   - Core Router        : online, brief 20-min outage ~28h ago
 *   - Distribution Switch: online, two 10-min timeout windows (~12h and ~36h ago)
 *   - File Server        : offline for the last 18h (currently down)
 *
 *   - Core Router HTTPS  : brief 15-min outage ~8h ago (resolved)
 *   - Distribution Switch HTTP: brief 10-min outage ~16h ago (resolved)
 *   - File Server SSH/SMB/NFS : all down (device offline)
 *
 * Idempotent: each section skips if data already exists for that device/service/alert.
 * Run DeviceSeed and MonitoredServiceSeed before this seed.
 *
 * Run all: php scripts/seed.php
 * Run only this: php scripts/seed.php MonitoringDataSeed
 */
class MonitoringDataSeed
{
    private DatabaseInterface $db;
    private int $now;

    /** Check interval in seconds (5 minutes). */
    private const INTERVAL = 300;

    /** History depth for device checks: 48 hours. */
    private const DEVICE_PERIODS = 576;

    /** History depth for service checks: 24 hours. */
    private const SERVICE_PERIODS = 288;

    public function __construct(DatabaseInterface $db)
    {
        $this->db  = $db;
        $this->now = time();
    }

    public function run(): array
    {
        $log = [];

        $log = array_merge($log, $this->seedDeviceChecks());
        $log = array_merge($log, $this->seedServiceChecks());

        [$alertLog, $alertIds] = $this->seedAlerts();
        $log = array_merge($log, $alertLog);

        $log = array_merge($log, $this->seedNotifications($alertIds));

        return $log;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Device checks
    // ─────────────────────────────────────────────────────────────────────────

    private function seedDeviceChecks(): array
    {
        $log     = [];
        $devices = $this->db->fetch(
            'SELECT id, name FROM devices WHERE deleted_at IS NULL ORDER BY id ASC'
        );

        if (empty($devices)) {
            $log[] = '  skip device checks: no devices found (run DeviceSeed first)';
            return $log;
        }

        $pdo  = $this->db->pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO device_checks (device_id, checked_at, status, latency_ms, created_at)
             VALUES (?, ?, ?, ?, ?)'
        );

        foreach ($devices as $device) {
            $deviceId   = (int) $device['id'];
            $deviceName = $device['name'];

            $existing = $this->db->fetchOne(
                'SELECT id FROM device_checks WHERE device_id = ? LIMIT 1',
                [$deviceId]
            );
            if ($existing !== null) {
                $log[] = "  skip device checks: {$deviceName} (already seeded)";
                continue;
            }

            $pdo->beginTransaction();
            $count = 0;

            // Insert from oldest (i = DEVICE_PERIODS-1) to newest (i = 0).
            for ($i = self::DEVICE_PERIODS - 1; $i >= 0; $i--) {
                $ts      = date('Y-m-d H:i:s', $this->now - $i * self::INTERVAL);
                $status  = $this->deviceCheckStatus($deviceName, $i);
                $latency = ($status === 'online') ? $this->deviceLatency($deviceName) : null;

                $stmt->execute([$deviceId, $ts, $status, $latency, $ts]);
                $count++;
            }

            $pdo->commit();
            $log[] = "  seeded device checks: {$deviceName} ({$count} rows)";
        }

        return $log;
    }

    /**
     * Determine check status for a device at history position $i.
     *
     * $i = 0   → most recent check (now)
     * $i = 1   → INTERVAL seconds ago
     * $i = N   → N * INTERVAL seconds ago
     */
    private function deviceCheckStatus(string $device, int $i): string
    {
        return match ($device) {
            // Offline for the last 18h (i=0..215), online before that.
            'File Server' => ($i < 216) ? 'offline' : 'online',

            // Brief 20-min offline blip ~28h ago (4 checks at i=330..333).
            'Core Router' => ($i >= 330 && $i <= 333) ? 'offline' : 'online',

            // Two 10-min timeout windows: ~12h ago (i=140,141) and ~36h ago (i=430,431).
            'Distribution Switch' => (($i >= 140 && $i <= 141) || ($i >= 430 && $i <= 431))
                ? 'timeout'
                : 'online',

            default => 'online',
        };
    }

    private function deviceLatency(string $device): int
    {
        return match ($device) {
            'Core Router'         => mt_rand(2, 8),
            'Distribution Switch' => mt_rand(3, 15),
            'File Server'         => mt_rand(12, 45),
            default               => mt_rand(5, 30),
        };
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Service checks
    // ─────────────────────────────────────────────────────────────────────────

    private function seedServiceChecks(): array
    {
        $log      = [];
        $services = $this->db->fetch(
            "SELECT ms.id, ms.name AS service_name, d.name AS device_name
             FROM   monitored_services ms
             JOIN   devices d ON d.id = ms.device_id
             WHERE  d.deleted_at IS NULL
             ORDER  BY d.name ASC, ms.name ASC"
        );

        if (empty($services)) {
            $log[] = '  skip service checks: no services found (run MonitoredServiceSeed first)';
            return $log;
        }

        $pdo  = $this->db->pdo();
        $stmt = $pdo->prepare(
            'INSERT INTO service_checks (service_id, checked_at, status, latency_ms, created_at)
             VALUES (?, ?, ?, ?, ?)'
        );

        foreach ($services as $svc) {
            $serviceId   = (int) $svc['id'];
            $serviceName = $svc['service_name'];
            $deviceName  = $svc['device_name'];

            $existing = $this->db->fetchOne(
                'SELECT id FROM service_checks WHERE service_id = ? LIMIT 1',
                [$serviceId]
            );
            if ($existing !== null) {
                $log[] = "  skip service checks: {$deviceName} / {$serviceName} (already seeded)";
                continue;
            }

            $lastStatus  = 'up';
            $lastChecked = date('Y-m-d H:i:s', $this->now);

            $pdo->beginTransaction();
            $count = 0;

            // Insert from oldest (i = SERVICE_PERIODS-1) to newest (i = 0).
            for ($i = self::SERVICE_PERIODS - 1; $i >= 0; $i--) {
                $ts      = date('Y-m-d H:i:s', $this->now - $i * self::INTERVAL);
                $status  = $this->serviceCheckStatus($serviceName, $deviceName, $i);
                $latency = ($status === 'up') ? $this->serviceLatency($serviceName) : null;

                $stmt->execute([$serviceId, $ts, $status, $latency, $ts]);
                $count++;

                if ($i === 0) {
                    $lastStatus  = $status;
                    $lastChecked = $ts;
                }
            }

            $pdo->commit();

            // Update the summary state on monitored_services.
            $this->db->execute(
                'UPDATE monitored_services SET last_state = ?, last_check_at = ? WHERE id = ?',
                [$lastStatus, $lastChecked, $serviceId]
            );

            $log[] = "  seeded service checks: {$deviceName} / {$serviceName} ({$count} rows, last: {$lastStatus})";
        }

        return $log;
    }

    /**
     * Determine check status for a service at history position $i.
     *
     * $i = 0 → most recent check (now)
     */
    private function serviceCheckStatus(string $service, string $device, int $i): string
    {
        // File Server services follow the device — down for the last 18h (i < 216).
        if ($device === 'File Server') {
            return ($i < 216) ? 'down' : 'up';
        }

        // Core Router HTTPS: brief 15-min outage ~8h ago (3 checks at i=94..96).
        if ($device === 'Core Router' && $service === 'HTTPS') {
            if ($i >= 94 && $i <= 96) {
                return 'down';
            }
        }

        // Distribution Switch HTTP: brief 10-min outage ~16h ago (2 checks at i=191,192).
        if ($device === 'Distribution Switch' && $service === 'HTTP') {
            if ($i >= 191 && $i <= 192) {
                return 'down';
            }
        }

        return 'up';
    }

    private function serviceLatency(string $service): int
    {
        return match ($service) {
            'SSH'   => mt_rand(5, 25),
            'HTTPS' => mt_rand(20, 120),
            'HTTP'  => mt_rand(15, 80),
            'SMB'   => mt_rand(8, 35),
            'NFS'   => mt_rand(10, 40),
            default => mt_rand(10, 50),
        };
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Alerts
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * @return array{array<string>, array<string, int>}
     *   [log lines, alert keys → IDs]
     */
    private function seedAlerts(): array
    {
        $log      = [];
        $alertIds = [];

        $definitions = $this->buildAlertDefinitions();
        if (empty($definitions)) {
            $log[] = '  skip alerts: required devices not found (run DeviceSeed first)';
            return [$log, $alertIds];
        }

        foreach ($definitions as $def) {
            $existing = $this->db->fetchOne(
                'SELECT id FROM alerts WHERE device_id = ? AND alert_type = ? LIMIT 1',
                [$def['device_id'], $def['alert_type']]
            );

            if ($existing !== null) {
                $alertIds[$def['key']] = (int) $existing['id'];
                $log[] = "  skip alert: {$def['label']} (already exists, id={$existing['id']})";
                continue;
            }

            $this->db->execute(
                'INSERT INTO alerts
                     (device_id, service_id, alert_type, status,
                      first_seen_at, last_seen_at, last_notified_at,
                      occurrence_count, resolved_at, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)',
                [
                    $def['device_id'],
                    $def['service_id'],
                    $def['alert_type'],
                    $def['status'],
                    $def['first_seen_at'],
                    $def['last_seen_at'],
                    $def['last_notified_at'],
                    $def['occurrence_count'],
                    $def['resolved_at'],
                    $def['first_seen_at'],
                ]
            );

            $id                    = (int) $this->db->lastInsertId();
            $alertIds[$def['key']] = $id;
            $log[] = "  seeded alert: {$def['label']} (id={$id}, status={$def['status']})";
        }

        return [$log, $alertIds];
    }

    private function buildAlertDefinitions(): array
    {
        $now = $this->now;

        $fileServer = $this->db->fetchOne(
            'SELECT id FROM devices WHERE name = ? AND deleted_at IS NULL',
            ['File Server']
        );
        $coreRouter = $this->db->fetchOne(
            'SELECT id FROM devices WHERE name = ? AND deleted_at IS NULL',
            ['Core Router']
        );
        $distSwitch = $this->db->fetchOne(
            'SELECT id FROM devices WHERE name = ? AND deleted_at IS NULL',
            ['Distribution Switch']
        );

        if ($fileServer === null || $coreRouter === null || $distSwitch === null) {
            return [];
        }

        $fsId = (int) $fileServer['id'];
        $crId = (int) $coreRouter['id'];
        $dsId = (int) $distSwitch['id'];

        // Optional service IDs — alerts are created without them if services are absent.
        $fsSsh = $this->db->fetchOne(
            'SELECT id FROM monitored_services WHERE device_id = ? AND name = ?',
            [$fsId, 'SSH']
        );
        $fsSmb = $this->db->fetchOne(
            'SELECT id FROM monitored_services WHERE device_id = ? AND name = ?',
            [$fsId, 'SMB']
        );
        $crHttps = $this->db->fetchOne(
            'SELECT id FROM monitored_services WHERE device_id = ? AND name = ?',
            [$crId, 'HTTPS']
        );
        $dsHttp = $this->db->fetchOne(
            'SELECT id FROM monitored_services WHERE device_id = ? AND name = ?',
            [$dsId, 'HTTP']
        );

        // File Server went offline 18h ago.
        $fsFirst       = date('Y-m-d H:i:s', $now - 18 * 3600);
        $fsLastSeen    = date('Y-m-d H:i:s', $now - 5 * 60);
        $fsLastNotify  = date('Y-m-d H:i:s', $now - 15 * 60);

        // Core Router HTTPS: brief outage 8h 15m ago, cleared 8h ago.
        $crHttpsFirst   = date('Y-m-d H:i:s', $now - 8 * 3600 - 15 * 60);
        $crHttpsLast    = date('Y-m-d H:i:s', $now - 8 * 3600);

        // Distribution Switch HTTP: brief outage 16h 10m ago, cleared 16h ago.
        $dsHttpFirst    = date('Y-m-d H:i:s', $now - 16 * 3600 - 10 * 60);
        $dsHttpLast     = date('Y-m-d H:i:s', $now - 16 * 3600);

        $defs = [
            // ── File Server device offline (open) ──────────────────────────
            [
                'key'             => 'fs_offline',
                'label'           => 'File Server / device_offline',
                'device_id'       => $fsId,
                'service_id'      => null,
                'alert_type'      => 'device_offline',
                'status'          => 'open',
                'first_seen_at'   => $fsFirst,
                'last_seen_at'    => $fsLastSeen,
                'last_notified_at'=> $fsLastNotify,
                'occurrence_count'=> 216,
                'resolved_at'     => null,
            ],
        ];

        // ── File Server SSH down (open, service-level) ─────────────────────
        if ($fsSsh !== null) {
            $defs[] = [
                'key'             => 'fs_ssh_down',
                'label'           => 'File Server / SSH service_down',
                'device_id'       => $fsId,
                'service_id'      => (int) $fsSsh['id'],
                'alert_type'      => 'service_down',
                'status'          => 'open',
                'first_seen_at'   => $fsFirst,
                'last_seen_at'    => $fsLastSeen,
                'last_notified_at'=> $fsLastNotify,
                'occurrence_count'=> 216,
                'resolved_at'     => null,
            ];
        }

        // ── File Server SMB down (open, service-level) ─────────────────────
        if ($fsSmb !== null) {
            $defs[] = [
                'key'             => 'fs_smb_down',
                'label'           => 'File Server / SMB service_down',
                'device_id'       => $fsId,
                'service_id'      => (int) $fsSmb['id'],
                'alert_type'      => 'service_down',
                'status'          => 'open',
                'first_seen_at'   => $fsFirst,
                'last_seen_at'    => $fsLastSeen,
                'last_notified_at'=> date('Y-m-d H:i:s', $now - 30 * 60),
                'occurrence_count'=> 216,
                'resolved_at'     => null,
            ];
        }

        // ── Core Router HTTPS down (resolved) ─────────────────────────────
        if ($crHttps !== null) {
            $defs[] = [
                'key'             => 'cr_https_down',
                'label'           => 'Core Router / HTTPS service_down',
                'device_id'       => $crId,
                'service_id'      => (int) $crHttps['id'],
                'alert_type'      => 'service_down',
                'status'          => 'resolved',
                'first_seen_at'   => $crHttpsFirst,
                'last_seen_at'    => $crHttpsLast,
                'last_notified_at'=> $crHttpsFirst,
                'occurrence_count'=> 3,
                'resolved_at'     => $crHttpsLast,
            ];
        }

        // ── Distribution Switch HTTP down (resolved) ───────────────────────
        if ($dsHttp !== null) {
            $defs[] = [
                'key'             => 'ds_http_down',
                'label'           => 'Distribution Switch / HTTP service_down',
                'device_id'       => $dsId,
                'service_id'      => (int) $dsHttp['id'],
                'alert_type'      => 'service_down',
                'status'          => 'resolved',
                'first_seen_at'   => $dsHttpFirst,
                'last_seen_at'    => $dsHttpLast,
                'last_notified_at'=> $dsHttpFirst,
                'occurrence_count'=> 2,
                'resolved_at'     => $dsHttpLast,
            ];
        }

        return $defs;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Notification history
    // ─────────────────────────────────────────────────────────────────────────

    private function seedNotifications(array $alertIds): array
    {
        $log       = [];
        $recipient = 'admin@netmon.local';
        $now       = $this->now;

        if (empty($alertIds)) {
            $log[] = '  skip notifications: no alert IDs available';
            return $log;
        }

        // Notification events per alert key: [type, seconds offset from $now, status]
        $definitions = [
            'fs_offline' => [
                ['open',     -18 * 3600,          'sent'],
                ['reminder', -18 * 3600 + 15 * 60, 'sent'],
                ['reminder', -17 * 3600,           'sent'],
                ['reminder', -(int)(16.75 * 3600), 'sent'],
                ['reminder', -(int)(0.25 * 3600),  'sent'],
            ],
            'fs_ssh_down' => [
                ['open',     -18 * 3600,          'sent'],
                ['reminder', -(int)(0.5 * 3600),   'sent'],
            ],
            'fs_smb_down' => [
                ['open',     -18 * 3600,           'sent'],
            ],
            'cr_https_down' => [
                ['open',     -(8 * 3600 + 15 * 60), 'sent'],
                ['resolved', -8 * 3600,              'sent'],
            ],
            'ds_http_down' => [
                ['open',     -(16 * 3600 + 10 * 60), 'sent'],
                ['resolved', -16 * 3600,              'sent'],
            ],
        ];

        foreach ($definitions as $key => $events) {
            if (!isset($alertIds[$key])) {
                $log[] = "  skip notifications for '{$key}': alert not seeded";
                continue;
            }

            $alertId = $alertIds[$key];

            $existing = $this->db->fetchOne(
                'SELECT id FROM notification_history WHERE alert_id = ? LIMIT 1',
                [$alertId]
            );
            if ($existing !== null) {
                $log[] = "  skip notifications: alert {$alertId} already has history";
                continue;
            }

            $count = 0;
            foreach ($events as [$type, $offset, $status]) {
                $ts = date('Y-m-d H:i:s', $now + $offset);
                $this->db->execute(
                    'INSERT INTO notification_history
                         (alert_id, channel, recipient, notification_type, status, message, sent_at, created_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)',
                    [$alertId, 'email', $recipient, $type, $status, null, $ts, $ts]
                );
                $count++;
            }

            $log[] = "  seeded notifications: alert {$alertId} / {$key} ({$count} rows)";
        }

        return $log;
    }
}
