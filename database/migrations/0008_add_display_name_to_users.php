<?php

use App\Core\Migration;

/**
 * Adds a display_name column to the users table.
 *
 * This column holds the user's full name as entered during registration
 * or installation.  It is separate from the login username.
 */
class AddDisplayNameToUsers extends Migration
{
    public function up(): void
    {
        $this->db->pdo()->exec(
            "ALTER TABLE users ADD COLUMN display_name VARCHAR(100) NOT NULL DEFAULT ''"
        );
    }

    public function down(): void
    {
        // SQLite < 3.35.0 does not support DROP COLUMN.
        // Recreate the table without the column to ensure clean rollback.
        $this->db->pdo()->exec('
            CREATE TABLE users_rollback (
                id            INTEGER      NOT NULL,
                username      VARCHAR(64)  NOT NULL,
                email         VARCHAR(255) NOT NULL,
                password_hash VARCHAR(255) NOT NULL,
                is_active     INTEGER      NOT NULL DEFAULT 1,
                created_at    VARCHAR(32)  NOT NULL,
                updated_at    VARCHAR(32)  NOT NULL,
                PRIMARY KEY (id)
            )
        ');

        $this->db->pdo()->exec('
            INSERT INTO users_rollback (id, username, email, password_hash, is_active, created_at, updated_at)
            SELECT id, username, email, password_hash, is_active, created_at, updated_at
            FROM users
        ');

        $this->db->pdo()->exec('DROP TABLE users');
        $this->db->pdo()->exec('ALTER TABLE users_rollback RENAME TO users');

        $this->db->pdo()->exec(
            'CREATE UNIQUE INDEX users_username_unique ON users (username)'
        );
        $this->db->pdo()->exec(
            'CREATE UNIQUE INDEX users_email_unique ON users (email)'
        );
    }
}
