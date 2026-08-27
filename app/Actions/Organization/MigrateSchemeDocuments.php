<?php

namespace App\Actions\Organization;

use App\Actions\Documents\MoveDocument;
use App\Models\Document;
use App\Models\OrganizationScheme;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\LazyCollection;
use InvalidArgumentException;

class MigrateSchemeDocuments
{
    public function __construct(
        private readonly FindAvailableLocation $findAvailableLocation,
        private readonly MoveDocument $moveDocument,
    ) {}

    /**
     * Move every document currently located under $source to a location
     * resolved under $target, using the same auto-placement rules
     * (ApplyOrganizationRules → FindAvailableLocation) a manual move uses.
     */
    public function handle(OrganizationScheme $source, OrganizationScheme $target): void
    {
        $this->assertDifferentSchemeInSameWorkspace($source, $target);

        $this->documentsUnder($source)->each(function (Document $document) use ($target): void {
            DB::transaction(function () use ($document, $target): void {
                $node = $this->findAvailableLocation->handle($target, [
                    'document_type' => $document->documentType->key,
                ]);

                $this->moveDocument->handle($document, $node);
            });
        });
    }

    /**
     * @return LazyCollection<int, Document>
     */
    private function documentsUnder(OrganizationScheme $source)
    {
        return Document::query()
            ->whereHas('currentLocation.node.level', fn ($query) => $query->where('scheme_id', $source->id))
            ->with('documentType')
            ->lazyById();
    }

    private function assertDifferentSchemeInSameWorkspace(OrganizationScheme $source, OrganizationScheme $target): void
    {
        if ($source->id === $target->id || $source->workspace_id !== $target->workspace_id) {
            throw new InvalidArgumentException('The target scheme must be a different scheme within the same workspace.');
        }
    }
}
