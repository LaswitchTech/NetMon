<?php

use App\Core\Migration;

class CreateUserGroupsTable extends Migration
{
    public function up(): void
    {
        $this->db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS user_groups (
                id       INTEGER NOT NULL,
                user_id  INTEGER NOT NULL,
                group_id INTEGER NOT NULL,
                PRIMARY KEY (id),
                FOREIGN KEY (user_id)  REFERENCES users(id)  ON DELETE CASCADE,
                FOREIGN KEY (group_id) REFERENCES groups(id) ON DELETE CASCADE
            )'
        );

        // Prevent duplicate memberships
        $this->db->pdo()->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS user_groups_unique ON user_groups (user_id, group_id)'
        );
    }

    public function down(): void
    {
        $this->db->pdo()->exec('DROP TABLE IF EXISTS user_groups');
    }
}
