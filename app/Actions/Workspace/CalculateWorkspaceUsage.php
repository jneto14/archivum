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
     * @param  Workspace  $workspace  The workspace to compute usage for.
     * @return array{storage_bytes: int, users: int, documents: int, attachments: int} Current usage totals.
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
     *
     * @param  Workspace  $workspace  The workspace whose attachments are summed.
     * @return int Total attachment size in bytes.
     */
    public function storageBytes(Workspace $workspace): int
    {
        return (int) $this->attachmentsQuery($workspace)->sum('size');
    }

    /**
     * Count the workspace's members.
     *
     * @param  Workspace  $workspace  The workspace whose members are counted.
     * @return int The number of members.
     */
    public function users(Workspace $workspace): int
    {
        return $workspace->users()->count();
    }

    /**
     * Count the workspace's documents.
     *
     * @param  Workspace  $workspace  The workspace whose documents are counted.
     * @return int The number of documents.
     */
    public function documents(Workspace $workspace): int
    {
        return Document::query()->where('workspace_id', $workspace->id)->count();
    }

    /**
     * Count attachments across the workspace's documents.
     *
     * @param  Workspace  $workspace  The workspace whose attachments are counted.
     * @return int The number of attachments.
     */
    public function attachments(Workspace $workspace): int
    {
        return $this->attachmentsQuery($workspace)->count();
    }

    /**
     * @param  Workspace  $workspace  The workspace to scope the attachments query to.
     * @return Builder<DocumentAttachment> Query builder for attachments belonging to $workspace's documents.
     */
    private function attachmentsQuery(Workspace $workspace): Builder
    {
        return DocumentAttachment::query()
            ->whereHas('document', fn ($query) => $query->where('workspace_id', $workspace->id));
    }
}
