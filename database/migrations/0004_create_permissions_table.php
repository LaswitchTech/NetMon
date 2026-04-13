<?php

use App\Core\Migration;

class CreatePermissionsTable extends Migration
{
    public function up(): void
    {
        // No timestamps: permissions are code-defined constants, not user-managed records.
        $this->db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS permissions (
                id          INTEGER      NOT NULL,
                name        VARCHAR(128) NOT NULL,
                description TEXT,
                PRIMARY KEY (id)
            )'
        );

        $this->db->pdo()->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS permissions_name_unique ON permissions (name)'
        );
    }

    public function down(): void
    {
        $this->db->pdo()->exec('DROP TABLE IF EXISTS permissions');
    }
}
