<?php

use App\Core\Migration;

/**
 * Add created_at and updated_at to the permissions table.
 *
 * The original schema omitted timestamps because permissions were conceived as
 * code-defined constants.  Now that permissions are user-manageable via the
 * Admin UI, timestamps are needed for the list view and for auditing.
 *
 * Existing rows are backfilled with the current timestamp.
 * SQLite allows ALTER TABLE … ADD COLUMN; the new columns are nullable so the
 * statement does not require a table rebuild.
 */
class AddTimestampsToPermissions extends Migration
{
    public function up(): void
    {
        $pdo = $this->db->pdo();

        $pdo->exec("ALTER TABLE permissions ADD COLUMN created_at VARCHAR(32) NULL");
        $pdo->exec("ALTER TABLE permissions ADD COLUMN updated_at VARCHAR(32) NULL");

        // Backfill existing rows so the list view always shows a sensible date.
        $now = date('Y-m-d H:i:s');
        $pdo->exec("UPDATE permissions SET created_at = '{$now}', updated_at = '{$now}' WHERE created_at IS NULL");
    }

    public function down(): void
    {
        // SQLite does not support DROP COLUMN before version 3.35.
        // Recreate the table without the timestamp columns as a safe fallback.
        $pdo = $this->db->pdo();

        $pdo->exec("CREATE TABLE IF NOT EXISTS permissions_backup AS SELECT id, name, description FROM permissions");
        $pdo->exec("DROP TABLE permissions");
        $pdo->exec("ALTER TABLE permissions_backup RENAME TO permissions");
        $pdo->exec("CREATE UNIQUE INDEX IF NOT EXISTS permissions_name_unique ON permissions (name)");
    }
}
