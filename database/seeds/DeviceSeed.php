<?php

use App\Core\DatabaseInterface;

/**
 * Inserts sample devices for development and demonstration purposes.
 *
 * This seed is idempotent: it skips any device whose name already exists,
 * so it is safe to run more than once.
 *
 * NOT wired into the installer by default — this is sample data, not required
 * bootstrap data. To apply manually, see docs/devices.md.
 */
class DeviceSeed
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

        $devices = [
            [
                'name'          => 'Core Router',
                'host'          => '192.168.1.1',
                'status'        => 'online',
                'last_check_at' => date('Y-m-d H:i:s', strtotime('-2 minutes')),
            ],
            [
                'name'          => 'Distribution Switch',
                'host'          => '192.168.1.2',
                'status'        => 'online',
                'last_check_at' => date('Y-m-d H:i:s', strtotime('-2 minutes')),
            ],
            [
                'name'          => 'File Server',
                'host'          => '192.168.1.10',
                'status'        => 'offline',
                'last_check_at' => date('Y-m-d H:i:s', strtotime('-15 minutes')),
            ],
        ];

        foreach ($devices as $d) {
            $existing = $this->db->fetchOne(
                'SELECT id FROM devices WHERE name = ?',
                [$d['name']]
            );

            if ($existing) {
                $log[] = "  skip device: {$d['name']} (already exists)";
                continue;
            }

            $this->db->execute(
                'INSERT INTO devices (name, host, status, last_check_at, created_at) VALUES (?, ?, ?, ?, ?)',
                [$d['name'], $d['host'], $d['status'], $d['last_check_at'], $now]
            );

            $log[] = "  seeded device: {$d['name']} ({$d['host']}, {$d['status']})";
        }

        return $log;
    }
}
