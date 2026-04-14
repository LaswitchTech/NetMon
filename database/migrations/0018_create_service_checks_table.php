<?php

use App\Core\Migration;

/**
 * Phase 8 — Service-level check history.
 *
 * Creates the service_checks table.
 *
 * Each row represents one check result for a monitored service.
 * This is an append-only historical log — rows are never updated after
 * insertion. Current service state is summarised in monitored_services
 * (last_state, last_check_at) for fast UI reads.
 *
 * Status values:
 *   'up'    — TCP connection succeeded within the timeout
 *   'down'  — TCP connection refused, timed out, or host unreachable
 *   'error' — Check could not run (e.g. invalid port, no target address)
 *
 * Note on retention: this table grows with every monitoring cycle.
 * A retention policy (e.g. keep the last 1 000 rows per service, or purge
 * rows older than 30 days) should be added before running in production.
 * The schema supports any retention strategy without modification.
 *
 * Relationship:
 *   monitored_services (1) ──< (many) service_checks
 */
class CreateServiceChecksTable extends Migration
{
    public function up(): void
    {
        $this->db->pdo()->exec(
            "CREATE TABLE IF NOT EXISTS service_checks (
                id         INTEGER      NOT NULL,
                service_id INTEGER      NOT NULL,
                checked_at VARCHAR(32)  NOT NULL,
                status     VARCHAR(16)  NOT NULL,
                latency_ms INTEGER,
                message    VARCHAR(255),
                created_at VARCHAR(32)  NOT NULL,
                PRIMARY KEY (id),
                FOREIGN KEY (service_id) REFERENCES monitored_services(id) ON DELETE CASCADE
            )"
        );

        // Fetch all checks for one service (history display, uptime calculation).
        $this->db->pdo()->exec(
            'CREATE INDEX IF NOT EXISTS service_checks_service_id
             ON service_checks (service_id)'
        );

        // Time-range queries across all services.
        $this->db->pdo()->exec(
            'CREATE INDEX IF NOT EXISTS service_checks_checked_at
             ON service_checks (checked_at)'
        );

        // Per-service time-range — most common pattern for history and graphs.
        $this->db->pdo()->exec(
            'CREATE INDEX IF NOT EXISTS service_checks_service_id_checked_at
             ON service_checks (service_id, checked_at)'
        );
    }

    public function down(): void
    {
        $this->db->pdo()->exec('DROP TABLE IF EXISTS service_checks');
    }
}
