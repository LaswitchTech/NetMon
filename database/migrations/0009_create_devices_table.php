<?php

use App\Core\Migration;

class CreateDevicesTable extends Migration
{
    public function up(): void
    {
        // Design notes:
        //   - host stores either an IP address or a hostname
        //   - status is a short label: 'online', 'offline', 'unknown'
        //     Stored as VARCHAR so future values (e.g. 'degraded') require no schema change
        //   - last_check_at is NULL until the first monitoring check runs
        //   - created_at records when the device record was added
        $this->db->pdo()->exec(
            "CREATE TABLE IF NOT EXISTS devices (
                id            INTEGER      NOT NULL,
                name          VARCHAR(128) NOT NULL,
                host          VARCHAR(255) NOT NULL,
                status        VARCHAR(32)  NOT NULL DEFAULT 'unknown',
                last_check_at VARCHAR(32),
                created_at    VARCHAR(32)  NOT NULL,
                PRIMARY KEY (id)
            )"
        );

        // Lookup by status for future monitoring queries (e.g. list all offline devices)
        $this->db->pdo()->exec(
            'CREATE INDEX IF NOT EXISTS devices_status ON devices (status)'
        );
    }

    public function down(): void
    {
        $this->db->pdo()->exec('DROP TABLE IF EXISTS devices');
    }
}
