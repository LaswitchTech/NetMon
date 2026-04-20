<?php

use App\Core\Migration;

/**
 * Notes module — polymorphic note attachment.
 *
 * A note is a free-text annotation attached to any domain entity via the
 * (entity_type, entity_id) pair.  There is intentionally NO foreign key on
 * entity_id — the entity may be a device, an alert, a discovery finding, or
 * any future object, and a polymorphic FK is not expressible in relational
 * SQL across entity types.  Application code is responsible for ensuring the
 * entity exists before creating a note.
 *
 * Deletion semantics:
 *   - Deleting a device, alert, or finding does NOT cascade-delete its notes.
 *     Notes are preserved even if the entity they describe is later removed.
 *   - Deleting a user sets notes.user_id = NULL (SET NULL) so the note is
 *     preserved but the author reference becomes anonymous.
 *
 * Supported entity_type values (first-class consumers):
 *   'device'   — devices.id
 *   'alert'    — alerts.id                   (future)
 *   'finding'  — discovery_findings.id       (future)
 *
 * Migration number: 0021 (next after discovery_findings 0020).
 */
class CreateNotesTable extends Migration
{
    public function up(): void
    {
        $this->db->pdo()->exec(
            "CREATE TABLE IF NOT EXISTS notes (
                id          INTEGER      NOT NULL,
                entity_type VARCHAR(64)  NOT NULL,
                entity_id   INTEGER      NOT NULL,
                user_id     INTEGER,
                content     TEXT         NOT NULL,
                created_at  VARCHAR(32)  NOT NULL,
                updated_at  VARCHAR(32)  NOT NULL,
                PRIMARY KEY (id),
                FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
            )"
        );

        // Hot path: fetch all notes for a given entity.
        $this->db->pdo()->exec(
            'CREATE INDEX IF NOT EXISTS notes_entity
             ON notes (entity_type, entity_id)'
        );

        // All notes authored by a given user (profile / moderation).
        $this->db->pdo()->exec(
            'CREATE INDEX IF NOT EXISTS notes_user_id
             ON notes (user_id)'
        );
    }

    public function down(): void
    {
        $this->db->pdo()->exec('DROP TABLE IF EXISTS notes');
    }
}
