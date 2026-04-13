<?php

use App\Core\Migration;

class CreateGroupPermissionsTable extends Migration
{
    public function up(): void
    {
        $this->db->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS group_permissions (
                id            INTEGER NOT NULL,
                group_id      INTEGER NOT NULL,
                permission_id INTEGER NOT NULL,
                PRIMARY KEY (id),
                FOREIGN KEY (group_id)      REFERENCES groups(id)      ON DELETE CASCADE,
                FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
            )'
        );

        // Prevent duplicate grants
        $this->db->pdo()->exec(
            'CREATE UNIQUE INDEX IF NOT EXISTS group_permissions_unique ON group_permissions (group_id, permission_id)'
        );
    }

    public function down(): void
    {
        $this->db->pdo()->exec('DROP TABLE IF EXISTS group_permissions');
    }
}
