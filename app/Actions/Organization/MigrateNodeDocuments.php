<?php

declare(strict_types=1);

namespace App\Actions\Organization;

use App\Actions\Documents\MoveDocument;
use App\Models\Document;
use App\Models\OrganizationNode;
use Illuminate\Support\LazyCollection;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

class MigrateNodeDocuments
{
    public function __construct(
        private readonly MoveDocument $moveDocument,
        private readonly CountFiledDocuments $countFiledDocuments,
    ) {}

    /**
     * Relocate every document currently located at $source onto $target.
     *
     * @param OrganizationNode $source The node whose documents are being moved.
     * @param OrganizationNode $target The node the documents are moved onto.
     *
     * @return void No return value on success.
     *
     * @throws InvalidArgumentException If $source and $target are the same node, or belong to different workspaces.
     * @throws ValidationException If $target cannot hold every document being moved.
     */
    public function handle(OrganizationNode $source, OrganizationNode $target): void
    {
        $this->assertDifferentNodeInSameWorkspace($source, $target);
        $this->assertTargetHasRoom($source, $target);

        $this->documentsAt($source)->each(
            fn (Document $document) => $this->moveDocument->handle($document, $target),
        );
    }

    /**
     * Refuse a migration the target cannot hold. MoveDocument turns away each document
     * that does not fit, so without this the move would stop partway and leave the
     * documents split across both nodes; checked here, the whole migration is refused
     * before any of it happens.
     *
     * Checked again by StartBulkDocumentMove before the task is queued, so the user is
     * told at the dialog rather than by a task that fails minutes later. If the archive
     * fills up in between, the job stops on the document that no longer fits and the
     * task fails; re-running it moves whatever is still at the source.
     *
     * @param OrganizationNode $source The node whose documents are being moved.
     * @param OrganizationNode $target The node the documents are moved onto.
     *
     * @return void No return value when the target has room.
     *
     * @throws ValidationException If $target is a leaf whose remaining room is smaller than the number of documents at $source.
     */
    public function assertTargetHasRoom(OrganizationNode $source, OrganizationNode $target): void
    {
        $level = $target->level;

        if ($level->capacity === null || !$level->isLeaf()) {
            return;
        }

        $moving = $this->countFiledDocuments->at($source);
        $filed = $this->countFiledDocuments->at($target);

        if ($filed + $moving > $level->capacity) {
            throw ValidationException::withMessages([
                'target_node_id' => __('organization.migration_target_full'),
            ]);
        }
    }

    /**
     * @param OrganizationNode $source The node to find current documents at.
     *
     * @return LazyCollection<int, Document> The documents currently located at $source.
     */
    private function documentsAt(OrganizationNode $source): LazyCollection
    {
        return Document::query()
            ->whereHas('currentLocation', fn ($query) => $query->where('organization_node_id', $source->id))
            ->lazyById();
    }

    /**
     * @param OrganizationNode $source The source node.
     * @param OrganizationNode $target The target node.
     *
     * @return void No return value when valid.
     *
     * @throws InvalidArgumentException If $source and $target are the same node, or belong to different workspaces.
     */
    private function assertDifferentNodeInSameWorkspace(OrganizationNode $source, OrganizationNode $target): void
    {
        if ($source->id === $target->id || $source->level->scheme->workspace_id !== $target->level->scheme->workspace_id) {
            throw new InvalidArgumentException('The target node must be a different node within the same workspace.');
        }
    }
}
