<?php

use App\Core\DatabaseInterface;

/**
 * Seeds sample discovery jobs and findings for development and demonstration.
 *
 * Creates:
 *   - discovery_jobs    : two subnet scan jobs (LAN + management network)
 *   - discovery_findings: matched, pending, and ignored findings across both jobs
 *
 * Finding states represented:
 *   - matched : linked to a device from DeviceSeed (3 findings)
 *   - pending : awaiting operator review (6 findings, one with a hostname that
 *               matches a matched finding — triggers a hostname suggestion in the UI)
 *   - ignored : dismissed by operator (1 finding)
 *
 * The 10.0.0.5 finding shares the hostname 'fileserver.lan' with the matched
 * 192.168.1.10 finding. Viewing 10.0.0.5 in the discovery UI will surface
 * "File Server" as a hostname-based match suggestion.
 *
 * Idempotent: jobs are matched by name; findings by (job_id, ip_address).
 * Run DeviceSeed first to enable device linking for matched findings.
 *
 * Run all: php scripts/seed.php
 * Run only this: php scripts/seed.php DiscoverySeed
 */
class DiscoverySeed
{
    private DatabaseInterface $db;

    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    public function run(): array
    {
        $log = [];
        $now = time();

        // ── Resolve device IDs from DeviceSeed ──────────────────────────────
        $deviceIds = [];
        foreach (['Core Router', 'Distribution Switch', 'File Server'] as $name) {
            $row = $this->db->fetchOne(
                'SELECT id FROM devices WHERE name = ? AND deleted_at IS NULL',
                [$name]
            );
            if ($row !== null) {
                $deviceIds[$name] = (int) $row['id'];
            }
        }

        if (empty($deviceIds)) {
            $log[] = '  note: no devices found — matched findings will have null matched_device_id (run DeviceSeed first)';
        }

        // ── Seed discovery jobs ──────────────────────────────────────────────
        $jobDefs = [
            [
                'name'        => 'LAN Scan',
                'subnet'      => '192.168.1.0/24',
                'enabled'     => 1,
                'last_run_at' => date('Y-m-d H:i:s', $now - 2 * 3600),
                'created_at'  => date('Y-m-d H:i:s', $now - 7 * 24 * 3600),
            ],
            [
                'name'        => 'Management Network',
                'subnet'      => '10.0.0.0/24',
                'enabled'     => 1,
                'last_run_at' => date('Y-m-d H:i:s', $now - 4 * 3600),
                'created_at'  => date('Y-m-d H:i:s', $now - 7 * 24 * 3600),
            ],
        ];

        $jobIds = [];
        foreach ($jobDefs as $job) {
            $existing = $this->db->fetchOne(
                'SELECT id FROM discovery_jobs WHERE name = ?',
                [$job['name']]
            );
            if ($existing !== null) {
                $jobIds[$job['name']] = (int) $existing['id'];
                $log[] = "  skip job: {$job['name']} (already exists)";
                continue;
            }

            $this->db->execute(
                'INSERT INTO discovery_jobs (name, subnet, enabled, last_run_at, created_at)
                 VALUES (?, ?, ?, ?, ?)',
                [$job['name'], $job['subnet'], $job['enabled'], $job['last_run_at'], $job['created_at']]
            );

            $jobIds[$job['name']] = (int) $this->db->lastInsertId();
            $log[] = "  seeded job: {$job['name']} ({$job['subnet']})";
        }

        $lanJobId  = $jobIds['LAN Scan']           ?? null;
        $mgmtJobId = $jobIds['Management Network'] ?? null;

        // ── Build findings ───────────────────────────────────────────────────
        $lanAt  = date('Y-m-d H:i:s', $now - 2 * 3600); // when LAN job last ran
        $mgmtAt = date('Y-m-d H:i:s', $now - 4 * 3600); // when Management job last ran

        $findings = [];

        if ($lanJobId !== null) {
            $findings = array_merge($findings, [
                // ── Matched findings (linked to devices from DeviceSeed) ─────
                [
                    'job_id'            => $lanJobId,
                    'ip_address'        => '192.168.1.1',
                    'mac_address'       => null,
                    'hostname'          => 'router.lan',
                    'status'            => 'matched',
                    'matched_device_id' => $deviceIds['Core Router'] ?? null,
                    'created_at'        => $lanAt,
                ],
                [
                    'job_id'            => $lanJobId,
                    'ip_address'        => '192.168.1.2',
                    'mac_address'       => null,
                    'hostname'          => 'switch01.lan',
                    'status'            => 'matched',
                    'matched_device_id' => $deviceIds['Distribution Switch'] ?? null,
                    'created_at'        => $lanAt,
                ],
                [
                    'job_id'            => $lanJobId,
                    'ip_address'        => '192.168.1.10',
                    'mac_address'       => null,
                    'hostname'          => 'fileserver.lan',
                    'status'            => 'matched',
                    'matched_device_id' => $deviceIds['File Server'] ?? null,
                    'created_at'        => $lanAt,
                ],
                // ── Pending findings ─────────────────────────────────────────
                [
                    'job_id'            => $lanJobId,
                    'ip_address'        => '192.168.1.50',
                    'mac_address'       => null,
                    'hostname'          => null,
                    'status'            => 'pending',
                    'matched_device_id' => null,
                    'created_at'        => $lanAt,
                ],
                [
                    'job_id'            => $lanJobId,
                    'ip_address'        => '192.168.1.51',
                    'mac_address'       => null,
                    'hostname'          => 'printer.lan',
                    'status'            => 'pending',
                    'matched_device_id' => null,
                    'created_at'        => $lanAt,
                ],
                [
                    'job_id'            => $lanJobId,
                    'ip_address'        => '192.168.1.52',
                    'mac_address'       => 'aa:bb:cc:dd:ee:01',
                    'hostname'          => null,
                    'status'            => 'pending',
                    'matched_device_id' => null,
                    'created_at'        => $lanAt,
                ],
                // ── Ignored finding ──────────────────────────────────────────
                [
                    'job_id'            => $lanJobId,
                    'ip_address'        => '192.168.1.99',
                    'mac_address'       => null,
                    'hostname'          => 'guest-device.lan',
                    'status'            => 'ignored',
                    'matched_device_id' => null,
                    'created_at'        => $lanAt,
                ],
            ]);
        }

        if ($mgmtJobId !== null) {
            $findings = array_merge($findings, [
                [
                    'job_id'            => $mgmtJobId,
                    'ip_address'        => '10.0.0.1',
                    'mac_address'       => null,
                    'hostname'          => 'mgmt-gw.lan',
                    'status'            => 'pending',
                    'matched_device_id' => null,
                    'created_at'        => $mgmtAt,
                ],
                // Shares hostname 'fileserver.lan' with the matched LAN finding
                // (192.168.1.10) → discovery UI will show File Server as a
                // hostname-based suggestion when reviewing this finding.
                [
                    'job_id'            => $mgmtJobId,
                    'ip_address'        => '10.0.0.5',
                    'mac_address'       => null,
                    'hostname'          => 'fileserver.lan',
                    'status'            => 'pending',
                    'matched_device_id' => null,
                    'created_at'        => $mgmtAt,
                ],
                [
                    'job_id'            => $mgmtJobId,
                    'ip_address'        => '10.0.0.10',
                    'mac_address'       => null,
                    'hostname'          => null,
                    'status'            => 'pending',
                    'matched_device_id' => null,
                    'created_at'        => $mgmtAt,
                ],
            ]);
        }

        // ── Insert findings ──────────────────────────────────────────────────
        foreach ($findings as $f) {
            $existing = $this->db->fetchOne(
                'SELECT id FROM discovery_findings WHERE job_id = ? AND ip_address = ?',
                [$f['job_id'], $f['ip_address']]
            );
            if ($existing !== null) {
                $log[] = "  skip finding: {$f['ip_address']} in job {$f['job_id']} (already exists)";
                continue;
            }

            $this->db->execute(
                'INSERT INTO discovery_findings
                     (job_id, ip_address, mac_address, hostname, status, matched_device_id, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $f['job_id'],
                    $f['ip_address'],
                    $f['mac_address'],
                    $f['hostname'],
                    $f['status'],
                    $f['matched_device_id'],
                    $f['created_at'],
                ]
            );

            $matchNote = $f['matched_device_id'] ? " → device #{$f['matched_device_id']}" : '';
            $log[] = "  seeded finding: {$f['ip_address']} ({$f['status']}{$matchNote})";
        }

        return $log;
    }
}
