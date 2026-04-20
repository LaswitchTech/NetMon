<?php

use App\Core\Migration;

/**
 * Admin audit log — immutable record of administrative actions.
 *
 * Each row records one admin action: who did what to which entity, and
 * a small JSON blob of context (changed fields, names, etc.).
 *
 * Rows are append-only.  There is no update or soft-delete path.
 * Deleting rows from this table directly is a deliberate out-of-band
 * operation (e.g. retention cleanup) and is not exposed in the UI.
 *
 * Columns:
 *   id           — auto-increment primary key
 *   user_id      — actor (the admin who performed the action); nullable to
 *                  support future system-initiated actions (NULL = system)
 *   action       — dot-namespaced verb, e.g. "user.create", "group.delete"
 *   entity_type  — domain object type: "user", "group"
 *   entity_id    — primary key of the affected row at the time of logging
 *   meta         — JSON text; small context blob (names, changed fields, counts)
 *   created_at   — timestamp of the action (server time, UTC)
 */
class CreateAdminAuditLogTable extends Migration
{
    public function up(): void
    {
        $this->db->pdo()->exec(
            "CREATE TABLE IF NOT EXISTS admin_audit_log (
                id          INTEGER      NOT NULL PRIMARY KEY AUTOINCREMENT,
                user_id     INTEGER      NULL,
                action      VARCHAR(64)  NOT NULL,
                entity_type VARCHAR(32)  NOT NULL,
                entity_id   INTEGER      NOT NULL,
                meta        TEXT         NOT NULL DEFAULT '{}',
                created_at  VARCHAR(32)  NOT NULL,
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
            )"
        );

        $this->db->pdo()->exec(
            'CREATE INDEX IF NOT EXISTS idx_audit_log_created_at
             ON admin_audit_log (created_at DESC)'
        );
    }

    public function down(): void
    {
        $this->db->pdo()->exec('DROP TABLE IF EXISTS admin_audit_log');
    }
}
