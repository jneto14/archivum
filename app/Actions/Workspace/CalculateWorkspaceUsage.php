<?php

namespace App\Actions\Workspace;

use App\Models\Document;
use App\Models\DocumentAttachment;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\Builder;

class CalculateWorkspaceUsage
{
    /**
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

    public function storageBytes(Workspace $workspace): int
    {
        return (int) $this->attachmentsQuery($workspace)->sum('size');
    }

    public function users(Workspace $workspace): int
    {
        return $workspace->users()->count();
    }

    public function documents(Workspace $workspace): int
    {
        return Document::query()->where('workspace_id', $workspace->id)->count();
    }

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
