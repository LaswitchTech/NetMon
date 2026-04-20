<?php

namespace App\Modules\Notes\Models;

use App\Core\DatabaseInterface;

/**
 * All database queries for the notes table.
 *
 * This repository is entity-agnostic: it knows only about (entity_type,
 * entity_id, user_id, content).  It has no dependency on DeviceRepository,
 * AlertRepository, or any NetMon-specific class — it belongs to the reusable
 * Notes module and may be used by any application built on this platform.
 *
 * Returns raw arrays; no domain objects.
 */
class NoteRepository
{
    private DatabaseInterface $db;

    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    // -------------------------------------------------------------------------
    // Read
    // -------------------------------------------------------------------------

    /**
     * Return all notes for an entity, newest first.
     *
     * JOINs the users table so callers receive author_name and author_display
     * without a second query.  Both columns are NULL when user_id is NULL
     * (author's account was deleted).
     *
     * @return array<int, array{
     *   id:             int,
     *   entity_type:    string,
     *   entity_id:      int,
     *   user_id:        int|null,
     *   content:        string,
     *   created_at:     string,
     *   updated_at:     string,
     *   author_name:    string|null,
     *   author_display: string|null
     * }>
     */
    public function findByEntity(string $entityType, int $entityId): array
    {
        return $this->db->fetch(
            "SELECT
                n.id,
                n.entity_type,
                n.entity_id,
                n.user_id,
                n.content,
                n.created_at,
                n.updated_at,
                u.username     AS author_name,
                u.display_name AS author_display
            FROM   notes n
            LEFT   JOIN users u ON u.id = n.user_id
            WHERE  n.entity_type = ?
              AND  n.entity_id   = ?
            ORDER  BY n.created_at DESC",
            [$entityType, $entityId]
        );
    }

    /**
     * Find a single note by ID.
     *
     * Returns null if the note does not exist.
     *
     * @return array{
     *   id:             int,
     *   entity_type:    string,
     *   entity_id:      int,
     *   user_id:        int|null,
     *   content:        string,
     *   created_at:     string,
     *   updated_at:     string,
     *   author_name:    string|null,
     *   author_display: string|null
     * }|null
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetchOne(
            "SELECT
                n.id,
                n.entity_type,
                n.entity_id,
                n.user_id,
                n.content,
                n.created_at,
                n.updated_at,
                u.username     AS author_name,
                u.display_name AS author_display
            FROM   notes n
            LEFT   JOIN users u ON u.id = n.user_id
            WHERE  n.id = ?",
            [$id]
        );
    }

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    /**
     * Insert a new note row.
     *
     * Sets created_at and updated_at to the same timestamp on creation.
     *
     * @param  array{
     *   entity_type: string,
     *   entity_id:   int,
     *   user_id:     int|null,
     *   content:     string
     * } $data
     * @return int  The new note ID
     */
    public function create(array $data): int
    {
        $now = date('Y-m-d H:i:s');

        $this->db->execute(
            "INSERT INTO notes (entity_type, entity_id, user_id, content, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?)",
            [
                $data['entity_type'],
                $data['entity_id'],
                $data['user_id'],
                $data['content'],
                $now,
                $now,
            ]
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * Hard-delete a note row.
     *
     * Notes have no soft-delete — removal is intentional and permanent.
     * There is no cascade from the note to any other table.
     */
    public function delete(int $id): void
    {
        $this->db->execute("DELETE FROM notes WHERE id = ?", [$id]);
    }
}
