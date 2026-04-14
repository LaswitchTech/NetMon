<?php

use App\Core\Migration;

/**
 * Phase 1 of the domain model evolution.
 *
 * Prepares the devices table for soft-delete and merge tracking by adding:
 *   - merged_into_device_id  Self-referencing FK. Non-null means this record
 *                            has been merged into another device and is soft-deleted.
 *   - deleted_at             Soft-delete timestamp. NULL = active record.
 *
 * Both columns are nullable with no default, so all existing rows are unaffected.
 * No existing data is migrated. No existing functionality changes.
 *
 * Future queries that list active devices must add:
 *   WHERE deleted_at IS NULL
 */
class AddMergeColumnsToDevices extends Migration
{
    public function up(): void
    {
        // Add self-referencing FK for merge tracking.
        // ON DELETE SET NULL: if the target (canonical) device is deleted, the
        // merged record becomes an orphan rather than being cascade-deleted.
        // SQLite does not enforce FK constraints on ADD COLUMN with REFERENCES,
        // but including the clause documents the intent and will be honoured
        // when a MySQLDriver is added.
        $this->db->pdo()->exec(
            'ALTER TABLE devices
             ADD COLUMN merged_into_device_id INTEGER REFERENCES devices(id) ON DELETE SET NULL'
        );

        // Soft-delete timestamp. NULL = the device is active.
        $this->db->pdo()->exec(
            'ALTER TABLE devices
             ADD COLUMN deleted_at VARCHAR(32)'
        );

        // Index supports the primary active-device query pattern:
        //   WHERE deleted_at IS NULL
        // SQLite includes NULL values in a standard index, so this index
        // is usable for IS NULL lookups.
        $this->db->pdo()->exec(
            'CREATE INDEX IF NOT EXISTS devices_deleted_at ON devices (deleted_at)'
        );
    }

    public function down(): void
    {
        // SQLite does not support DROP COLUMN on versions before 3.35.0.
        // Recreate the table without the two added columns, copy data, swap.

        $this->db->pdo()->exec('
            CREATE TABLE devices_rollback (
                id            INTEGER      NOT NULL,
                name          VARCHAR(128) NOT NULL,
                host          VARCHAR(255) NOT NULL,
                status        VARCHAR(32)  NOT NULL DEFAULT \'unknown\',
                last_check_at VARCHAR(32),
                created_at    VARCHAR(32)  NOT NULL,
                PRIMARY KEY (id)
            )
        ');

        // Copy only the original columns; merged_into_device_id and deleted_at are dropped.
        $this->db->pdo()->exec('
            INSERT INTO devices_rollback (id, name, host, status, last_check_at, created_at)
            SELECT id, name, host, status, last_check_at, created_at
            FROM devices
        ');

        $this->db->pdo()->exec('DROP TABLE devices');
        $this->db->pdo()->exec('ALTER TABLE devices_rollback RENAME TO devices');

        // Restore original index.
        $this->db->pdo()->exec(
            'CREATE INDEX IF NOT EXISTS devices_status ON devices (status)'
        );
    }
}
