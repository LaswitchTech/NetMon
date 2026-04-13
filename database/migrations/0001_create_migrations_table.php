<?php

use App\Core\Migration;

/**
 * Creates the migrations tracking table.
 *
 * This migration is intentionally simple — the MigrationRunner bootstraps the
 * table automatically before running any migrations, so this file serves as the
 * canonical schema record and provides a clean rollback path.
 */
class CreateMigrationsTable extends Migration
{
    public function up(): void
    {
        $this->db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS migrations (
                id         INTEGER      NOT NULL,
                name       VARCHAR(255) NOT NULL,
                batch      INTEGER      NOT NULL,
                applied_at VARCHAR(32)  NOT NULL,
                PRIMARY KEY (id)
            )'
        );
    }

    public function down(): void
    {
        $this->db->pdo()->exec('DROP TABLE IF EXISTS migrations');
    }
}
