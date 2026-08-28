<?php

declare(strict_types=1);

namespace App\Actions\Organization;

use App\Models\Document;
use App\Models\OrganizationNode;
use Illuminate\Validation\ValidationException;

class DeleteOrganizationNode
{
    /**
     * Delete a single organization node.
     *
     * @param OrganizationNode $node The node to delete.
     *
     * @return void No return value on success.
     *
     * @throws ValidationException If $node has child nodes, or has documents currently located at it.
     */
    public function handle(OrganizationNode $node): void
    {
        $this->assertHasNoChildren($node);
        $this->assertHasNoDocuments($node);

        $node->delete();
    }

    /**
     * @param OrganizationNode $node The node to check.
     *
     * @return void No return value when $node has no children.
     *
     * @throws ValidationException If $node has child nodes.
     */
    private function assertHasNoChildren(OrganizationNode $node): void
    {
        if ($node->children()->exists()) {
            throw ValidationException::withMessages([
                'node' => __('organization.node_has_children'),
            ]);
        }
    }

    /**
     * @param OrganizationNode $node The node to check.
     *
     * @return void No return value when $node has no documents.
     *
     * @throws ValidationException If any document's current location is $node.
     */
    private function assertHasNoDocuments(OrganizationNode $node): void
    {
        $hasDocuments = Document::query()
            ->whereHas('currentLocation', fn ($query) => $query->where('organization_node_id', $node->id))
            ->exists();

        if ($hasDocuments) {
            throw ValidationException::withMessages([
                'node' => __('organization.node_has_documents'),
            ]);
        }
    }
}
