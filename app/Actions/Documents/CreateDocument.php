<?php

namespace App\Actions\Documents;

use App\Actions\Workspace\CalculateWorkspaceUsage;
use App\Models\Document;
use App\Models\DocumentType;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateDocument
{
    public function __construct(private readonly CalculateWorkspaceUsage $calculateUsage) {}

    /**
     * Create a new Document and sync its tags.
     *
     * @param  array<string, mixed>|null  $metadata
     * @param  array<int, string>  $tagIds
     */
    public function handle(Workspace $workspace, User $creator, DocumentType $type, string $title, ?string $documentDate, ?array $metadata, array $tagIds = []): Document
    {
        if ($workspace->limits?->exceedsDocuments($this->calculateUsage->documents($workspace))) {
            throw ValidationException::withMessages([
                'workspace' => __('This workspace has reached its document limit.'),
            ]);
        }

        return DB::transaction(function () use ($workspace, $creator, $type, $title, $documentDate, $metadata, $tagIds): Document {
            $document = Document::query()->create([
                'workspace_id' => $workspace->id,
                'document_type_id' => $type->id,
                'created_by' => $creator->id,
                'title' => $title,
                'document_date' => $documentDate,
                'metadata' => $metadata,
            ]);

            $document->tags()->sync($tagIds);

            return $document;
        });
    }
}
