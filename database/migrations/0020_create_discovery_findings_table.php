<?php

use App\Core\Migration;

/**
 * Discovery — Step 2 of 2.
 *
 * Creates the discovery_findings table.
 *
 * A discovery finding is an observed host (IP address) that responded to an
 * ICMP ping during a subnet scan. Findings are informational only — they never
 * automatically create or merge devices.
 *
 * Status lifecycle:
 *   pending  → The IP was observed but does not correspond to any known device.
 *              Operator should review and decide whether to add it as a device.
 *   matched  → The IP already exists in device_addresses and has been linked to
 *              an existing device (matched_device_id is set).
 *   ignored  → Operator has explicitly dismissed this finding (future UI action).
 *
 * Relationship:
 *   discovery_jobs     (1) ──< (many) discovery_findings
 *   devices            (1) ──< (many) discovery_findings  [nullable, via matched_device_id]
 *
 * Design notes:
 *   - mac_address and hostname are NULL for now (future: ARP + reverse DNS)
 *   - A UNIQUE constraint on (job_id, ip_address) prevents duplicate findings
 *     for the same IP within the same job; re-scans update the existing row
 *   - matched_device_id is NULL until the finding is matched to a device
 */
class CreateDiscoveryFindingsTable extends Migration
{
    public function up(): void
    {
        $this->db->pdo()->exec(
            "CREATE TABLE IF NOT EXISTS discovery_findings (
                id                INTEGER     NOT NULL,
                job_id            INTEGER     NOT NULL,
                ip_address        VARCHAR(45) NOT NULL,
                mac_address       VARCHAR(17),
                hostname          VARCHAR(253),
                status            VARCHAR(16) NOT NULL DEFAULT 'pending',
                matched_device_id INTEGER,
                created_at        VARCHAR(32) NOT NULL,
                PRIMARY KEY (id),
                UNIQUE (job_id, ip_address),
                FOREIGN KEY (job_id)            REFERENCES discovery_jobs(id) ON DELETE CASCADE,
                FOREIGN KEY (matched_device_id) REFERENCES devices(id)        ON DELETE SET NULL
            )"
        );

        // Look up all findings for a given job (main list view).
        $this->db->pdo()->exec(
            'CREATE INDEX IF NOT EXISTS discovery_findings_job_id
             ON discovery_findings (job_id)'
        );

        // Match an observed IP against existing findings (dedup check in runner).
        $this->db->pdo()->exec(
            'CREATE INDEX IF NOT EXISTS discovery_findings_ip_address
             ON discovery_findings (ip_address)'
        );

        // Filter by status for the UI (pending list, matched list).
        $this->db->pdo()->exec(
            'CREATE INDEX IF NOT EXISTS discovery_findings_status
             ON discovery_findings (status)'
        );
    }

    public function down(): void
    {
        $this->db->pdo()->exec('DROP TABLE IF EXISTS discovery_findings');
    }
}
