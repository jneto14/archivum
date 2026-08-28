<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The kind of background work a Task record represents.
 */
enum TaskType: string
{
    case DocumentExport = 'document_export';

    /**
     * The Cache::lock() key used to prevent two tasks of this type running
     * concurrently for the same workspace.
     *
     * @param string $workspaceId The workspace the task belongs to.
     *
     * @return string The lock key.
     */
    public function lockKey(string $workspaceId): string
    {
        return match ($this) {
            self::DocumentExport => "workspace:{$workspaceId}:export:documents",
        };
    }
}
