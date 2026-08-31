<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The kind of background work a Task record represents.
 */
enum TaskType: string
{
    case DocumentExport = 'document_export';
    case BulkDocumentMove = 'bulk_document_move';
    case AttachmentTextExtraction = 'attachment_text_extraction';

    /**
     * The Cache::lock() key used to prevent two tasks of this type running
     * concurrently for the same workspace, or null when the type has no such
     * restriction.
     *
     * Export and bulk move each sweep the whole workspace, so a second one
     * running alongside would duplicate work or fight over the same rows.
     * Attachment text extraction is the opposite: it is scoped to one file,
     * several can run at once without interfering, and a workspace-wide lock
     * would serialise a queue that has every reason to be parallel.
     *
     * @param string $workspaceId The workspace the task belongs to.
     *
     * @return string|null The lock key, or null when tasks of this type may run concurrently.
     */
    public function lockKey(string $workspaceId): ?string
    {
        return match ($this) {
            self::DocumentExport => "workspace:{$workspaceId}:export:documents",
            self::BulkDocumentMove => "workspace:{$workspaceId}:bulk-move:documents",
            self::AttachmentTextExtraction => null,
        };
    }
}
