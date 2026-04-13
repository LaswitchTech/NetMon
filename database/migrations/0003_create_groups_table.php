<?php

use App\Core\Migration;

class CreateGroupsTable extends Migration
{
    public function up(): void
    {
        $this->db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS groups (
                id          INTEGER     NOT NULL,
                name        VARCHAR(64) NOT NULL,
                description TEXT,
                created_at  VARCHAR(32) NOT NULL,
                updated_at  VARCHAR(32) NOT NULL,
                PRIMARY KEY (id)
            )'
        );

        $this->db->pdo()->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS groups_name_unique ON groups (name)'
        );
    }

    public function down(): void
    {
        $this->db->pdo()->exec('DROP TABLE IF EXISTS groups');
    }
}
