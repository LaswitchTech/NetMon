<?php

use App\Core\Migration;

class CreateUsersTable extends Migration
{
    public function up(): void
    {
        $this->db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS users (
                id            INTEGER      NOT NULL,
                username      VARCHAR(64)  NOT NULL,
                email         VARCHAR(255) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                is_active     INTEGER      NOT NULL DEFAULT 1,
                created_at    VARCHAR(32)  NOT NULL,
                updated_at    VARCHAR(32)  NOT NULL,
                PRIMARY KEY (id)
            )'
        );

        $this->db->pdo()->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS users_username_unique ON users (username)'
        );

        $this->db->pdo()->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS users_email_unique ON users (email)'
        );
    }

    public function down(): void
    {
        $this->db->pdo()->exec('DROP TABLE IF EXISTS users');
    }
}
