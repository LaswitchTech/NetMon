<?php

use App\Core\Migration;

/**
 * Discovery — Step 1 of 2.
 *
 * Creates the discovery_jobs table.
 *
 * A discovery job describes a subnet to scan. Enabled jobs are picked up by
 * the discovery runner (scripts/discover.php) and scanned via ICMP ping sweep.
 *
 * Relationship:
 *   discovery_jobs (1) ──< (many) discovery_findings
 *
 * Design notes:
 *   - subnet stores a CIDR string, e.g. '192.168.1.0/24'
 *   - enabled = 1 (run this job) or 0 (skip)
 *   - last_run_at is NULL until the first scan completes
 */
class CreateDiscoveryJobsTable extends Migration
{
    public function up(): void
    {
        $this->db->pdo()->exec(
            "CREATE TABLE IF NOT EXISTS discovery_jobs (
                id          INTEGER      NOT NULL,
                name        VARCHAR(128) NOT NULL,
                subnet      VARCHAR(45)  NOT NULL,
                enabled     INTEGER      NOT NULL DEFAULT 1,
                last_run_at VARCHAR(32),
                created_at  VARCHAR(32)  NOT NULL,
                PRIMARY KEY (id)
            )"
        );

        // Filter for only enabled jobs (used by the scanner on every run).
        $this->db->pdo()->exec(
            'CREATE INDEX IF NOT EXISTS discovery_jobs_enabled
             ON discovery_jobs (enabled)'
        );
    }

    public function down(): void
    {
        $this->db->pdo()->exec('DROP TABLE IF EXISTS discovery_jobs');
    }
}
