<?php

use App\Core\DatabaseInterface;

/**
 * Seeds sample monitored services for development and demonstration.
 *
 * Attaches representative TCP services to each of the three devices
 * created by DeviceSeed. Run DeviceSeed first.
 *
 * Idempotent: skips any service whose (device_id, name) already exists,
 * so it is safe to run more than once.
 *
 * NOT wired into the installer — this is dev/demo data only.
 * Run via: php scripts/seed.php MonitoredServiceSeed
 */
class MonitoredServiceSeed
{
    private DatabaseInterface $db;

    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    public function run(): array
    {
        $log = [];
        $now = date('Y-m-d H:i:s');

        // Map: device name → list of services to seed.
        $map = [
            'Core Router' => [
                ['name' => 'SSH',   'protocol' => 'tcp', 'port' => 22],
                ['name' => 'HTTPS', 'protocol' => 'tcp', 'port' => 443],
            ],
            'Distribution Switch' => [
                ['name' => 'SSH',  'protocol' => 'tcp', 'port' => 22],
                ['name' => 'HTTP', 'protocol' => 'tcp', 'port' => 80],
            ],
            'File Server' => [
                ['name' => 'SSH', 'protocol' => 'tcp', 'port' => 22],
                ['name' => 'SMB', 'protocol' => 'tcp', 'port' => 445],
                ['name' => 'NFS', 'protocol' => 'tcp', 'port' => 2049],
            ],
        ];

        foreach ($map as $deviceName => $services) {
            $device = $this->db->fetchOne(
                'SELECT id FROM devices WHERE name = ? AND deleted_at IS NULL',
                [$deviceName]
            );

            if ($device === null) {
                $log[] = "  skip: device '{$deviceName}' not found (run DeviceSeed first)";
                continue;
            }

            $deviceId = (int) $device['id'];

            foreach ($services as $svc) {
                $existing = $this->db->fetchOne(
                    'SELECT id FROM monitored_services WHERE device_id = ? AND name = ?',
                    [$deviceId, $svc['name']]
                );

                if ($existing !== null) {
                    $log[] = "  skip service: {$deviceName} / {$svc['name']} (already exists)";
                    continue;
                }

                $this->db->execute(
                    "INSERT INTO monitored_services
                         (device_id, name, protocol, port, monitoring_enabled, expected_state, created_at)
                     VALUES (?, ?, ?, ?, 1, 'up', ?)",
                    [$deviceId, $svc['name'], $svc['protocol'], $svc['port'], $now]
                );

                $log[] = "  seeded service: {$deviceName} / {$svc['name']} ({$svc['protocol']}:{$svc['port']})";
            }
        }

        return $log;
    }
}
