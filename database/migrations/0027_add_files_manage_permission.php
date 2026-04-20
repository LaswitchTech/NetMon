<?php

use App\Core\Migration;

/**
 * Add the files.manage permission and grant it to the admin group.
 *
 * files.manage gates all File Manager routes (browse, upload, download, delete).
 * It is granted to the admin group by default.  Future phases may grant it to
 * narrower groups (e.g. a dedicated "file_managers" group) without removing admin access.
 *
 * The INSERT ... WHERE NOT EXISTS pattern makes this migration idempotent:
 * running it more than once is safe and produces no duplicate rows.
 */
class AddFilesManagePermission extends Migration
{
    public function up(): void
    {
        // Insert the permission if it does not already exist.
        $this->db->pdo()->exec(
            "INSERT INTO permissions (name, description)
             SELECT 'files.manage', 'Browse, upload, download, and delete files in configured storage roots'
             WHERE NOT EXISTS (SELECT 1 FROM permissions WHERE name = 'files.manage')"
        );

        // Grant files.manage to the admin group if the grant does not already exist.
        $this->db->pdo()->exec(
            "INSERT INTO group_permissions (group_id, permission_id)
             SELECT g.id, p.id
             FROM groups g
             CROSS JOIN permissions p
             WHERE g.name = 'admin'
               AND p.name = 'files.manage'
               AND NOT EXISTS (
                   SELECT 1 FROM group_permissions gp2
                   WHERE gp2.group_id = g.id AND gp2.permission_id = p.id
               )"
        );
    }

    public function down(): void
    {
        $this->db->pdo()->exec(
            "DELETE FROM group_permissions
             WHERE permission_id = (SELECT id FROM permissions WHERE name = 'files.manage')"
        );

        $this->db->pdo()->exec(
            "DELETE FROM permissions WHERE name = 'files.manage'"
        );
    }
}
