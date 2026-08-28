<?php

declare(strict_types=1);

namespace App\Actions\Organization;

use App\Actions\Documents\MoveDocument;
use App\Models\Document;
use App\Models\OrganizationNode;
use Illuminate\Support\LazyCollection;
use InvalidArgumentException;

class MigrateNodeDocuments
{
    public function __construct(
        private readonly MoveDocument $moveDocument,
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
     */
    public function handle(OrganizationNode $source, OrganizationNode $target): void
    {
        $this->assertDifferentNodeInSameWorkspace($source, $target);

        $this->documentsAt($source)->each(
            fn (Document $document) => $this->moveDocument->handle($document, $target),
        );
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
