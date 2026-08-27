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
     * @param  Workspace  $workspace  The workspace the document belongs to; its usage limits are enforced.
     * @param  User  $creator  The user recorded as the document's creator.
     * @param  DocumentType  $type  The document type assigned to the document.
     * @param  string  $title  The document's title.
     * @param  string|null  $documentDate  The date the document was issued/dated, if known.
     * @param  array<string, mixed>|null  $metadata  Arbitrary type-specific metadata to store alongside the document.
     * @param  array<int, string>  $tagIds  IDs of tags to attach to the document.
     * @return Document The newly created document, with its tags synced.
     *
     * @throws ValidationException If the workspace has reached its document limit.
     */
    public function handle(Workspace $workspace, User $creator, DocumentType $type, string $title, ?string $documentDate, ?array $metadata, array $tagIds = []): Document
    {
        if ($workspace->limits?->exceedsDocuments($this->calculateUsage->documents($workspace))) {
            throw ValidationException::withMessages([
                'workspace' => __('document.limit_reached'),
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
