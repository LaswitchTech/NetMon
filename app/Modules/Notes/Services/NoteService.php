<?php

namespace App\Modules\Notes\Services;

use App\Modules\Notes\Models\NoteRepository;

/**
 * Thin validation and write orchestration for the Notes module.
 *
 * Enforces content rules and ownership checks before delegating to
 * NoteRepository.  Has no dependency on any NetMon-specific class.
 */
class NoteService
{
    // Maximum content length enforced at the service layer.
    // The database TEXT column has no hard limit; this is a UX guardrail.
    public const MAX_CONTENT_LENGTH = 10000;

    private NoteRepository $repo;

    public function __construct(NoteRepository $repo)
    {
        $this->repo = $repo;
    }

    // -------------------------------------------------------------------------
    // Write
    // -------------------------------------------------------------------------

    /**
     * Validate and create a note.
     *
     * Throws \InvalidArgumentException if content is empty or too long.
     *
     * @param  string   $entityType  e.g. 'device', 'alert', 'finding'
     * @param  int      $entityId    Primary key of the target entity
     * @param  int|null $userId      Authenticated user ID; null = anonymous
     * @param  string   $content     Note body (will be trimmed before storage)
     * @return int                   New note ID
     * @throws \InvalidArgumentException
     */
    public function addNote(
        string $entityType,
        int $entityId,
        ?int $userId,
        string $content
    ): int {
        $content = trim($content);

        if ($content === '') {
            throw new \InvalidArgumentException('Note content cannot be empty.');
        }

        if (mb_strlen($content) > self::MAX_CONTENT_LENGTH) {
            throw new \InvalidArgumentException(
                'Note content must be ' . number_format(self::MAX_CONTENT_LENGTH) . ' characters or fewer.'
            );
        }

        return $this->repo->create([
            'entity_type' => $entityType,
            'entity_id'   => $entityId,
            'user_id'     => $userId,
            'content'     => $content,
        ]);
    }

    /**
     * Remove a note, enforcing simple ownership rules.
     *
     * Authorization rules (v1):
     *   - A note can be deleted by its author (user_id == requestingUserId).
     *   - If $isAdmin is true, the requesting user may delete any note
     *     regardless of authorship (reserved for future admin capability).
     *   - Notes with user_id = NULL (anonymous / deleted author) can only be
     *     deleted when $isAdmin is true — they have no owner to authorize.
     *
     * @param  int      $noteId            The note to delete
     * @param  int|null $requestingUserId  The authenticated user's ID
     * @param  bool     $isAdmin           Whether the requesting user is an admin
     * @throws \InvalidArgumentException   If the note is not found or unauthorized
     */
    public function removeNote(int $noteId, ?int $requestingUserId, bool $isAdmin = false): void
    {
        $note = $this->repo->findById($noteId);

        if ($note === null) {
            throw new \InvalidArgumentException('Note not found.');
        }

        $isAuthor = $requestingUserId !== null && (int) $note['user_id'] === $requestingUserId;

        if (!$isAuthor && !$isAdmin) {
            throw new \InvalidArgumentException('You do not have permission to delete this note.');
        }

        $this->repo->delete($noteId);
    }
}
