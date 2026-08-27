<?php

namespace App\Actions\Workspace;

use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;

class CalculateWorkspaceUsage
{
    /**
     * Compute all workspace usage totals in one call.
     *
     * @return array{storage_bytes: int, users: int, documents: int, attachments: int}
     */
    public function handle(Workspace $workspace): array
    {
        return [
            'storage_bytes' => $this->storageBytes($workspace),
            'users' => $this->users($workspace),
            'documents' => $this->documents($workspace),
            'attachments' => $this->attachments($workspace),
        ];
    }

    /**
     * Sum the byte size of all attachments belonging to the workspace's documents.
     */
    public function storageBytes(Workspace $workspace): int
    {
        return (int) $this->attachmentsQuery($workspace)->sum('size');
    }

    /**
     * Count the workspace's members.
     */
    public function users(Workspace $workspace): int
    {
        return $workspace->users()->count();
    }

    /**
     * Count the workspace's documents.
     */
    public function documents(Workspace $workspace): int
    {
        return Document::query()->where('workspace_id', $workspace->id)->count();
    }

    /**
     * Count attachments across the workspace's documents.
     */
    public function attachments(Workspace $workspace): int
    {
        return $this->attachmentsQuery($workspace)->count();
    }

    /**
     * @return Builder<DocumentAttachment>
     */
    private function attachmentsQuery(Workspace $workspace): Builder
    {
        return DocumentAttachment::query()
            ->whereHas('document', fn ($query) => $query->where('workspace_id', $workspace->id));
    }
}
