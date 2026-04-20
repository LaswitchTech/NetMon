<?php

namespace App\Models;

use App\Core\DatabaseInterface;

/**
 * Read/write access to the admin_audit_log table.
 *
 * Rows are append-only — this repository never updates or deletes entries.
 *
 * Usage in controllers:
 *   Wrap the call in a try/catch so a DB failure never breaks the main operation.
 *   The AdminAuditLogger service does this automatically.
 */
class AuditLogRepository
{
    private DatabaseInterface $db;

    public function __construct(DatabaseInterface $db)
    {
        $this->db = $db;
    }

    /**
     * Append one audit log entry.
     *
     * @param int|null $userId     Actor user ID (null = system/anonymous).
     * @param string   $action     Dot-namespaced verb: "user.create", "group.delete", etc.
     * @param string   $entityType Domain object type: "user", "group".
     * @param int      $entityId   Primary key of the affected row.
     * @param array    $meta       Small context array (names, counts, changed fields).
     *                             Will be JSON-encoded. Keep it small and human-readable.
     */
    public function log(
        ?int   $userId,
        string $action,
        string $entityType,
        int    $entityId,
        array  $meta = []
    ): void {
        $this->db->execute(
            'INSERT INTO admin_audit_log (user_id, action, entity_type, entity_id, meta, created_at)
             VALUES (?, ?, ?, ?, ?, ?)',
            [
                $userId,
                $action,
                $entityType,
                $entityId,
                json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                date('Y-m-d H:i:s'),
            ]
        );
    }

    /**
     * Return the most recent audit log entries, joined with the actor's display info.
     *
     * Each row includes all admin_audit_log columns plus:
     *   actor_display_name — the actor's display_name (or username if no display_name)
     *   actor_username     — the actor's username
     *
     * actor_* columns are NULL when user_id is NULL (system action) or when the
     * actor account has been deleted (FOREIGN KEY ON DELETE SET NULL).
     *
     * @param  int   $limit  Max rows to return (default 500).
     * @return array<int, array>
     */
    public function findRecent(int $limit = 500): array
    {
        return $this->db->fetch(
            'SELECT
                a.id,
                a.user_id,
                a.action,
                a.entity_type,
                a.entity_id,
                a.meta,
                a.created_at,
                u.display_name AS actor_display_name,
                u.username     AS actor_username
             FROM admin_audit_log a
             LEFT JOIN users u ON u.id = a.user_id
             ORDER BY a.created_at DESC, a.id DESC
             LIMIT ?',
            [$limit]
        );
    }
}
