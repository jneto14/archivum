<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Models\DocumentType;
use App\Models\OrganizationNode;
use App\Models\OrganizationScheme;
use App\Models\Tag;
use App\Models\Workspace;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Resolve client-supplied ids against one workspace.
 *
 * The validation rules behind these say `exists:document_types,id` and
 * `exists:tags,id`, which is an existence check and not an ownership one: a
 * request naming another workspace's type passes validation. What stops it is
 * resolving the id here, scoped, and 404ing when it does not belong.
 *
 * Shared rather than repeated because the session flow and the API flow both
 * need it and this is the kind of check that goes wrong by drifting — two
 * copies, one of them later relaxed.
 */
trait ResolvesWorkspaceRecords
{
    /**
     * Resolve a document type by id, scoped to the given workspace.
     *
     * @param Workspace $workspace The workspace the type must belong to.
     * @param string $documentTypeId The UUID of the requested document type.
     *
     * @return DocumentType The matching document type.
     *
     * @throws ModelNotFoundException If no type with $documentTypeId exists within $workspace.
     */
    protected function scopedDocumentType(Workspace $workspace, string $documentTypeId): DocumentType
    {
        return DocumentType::query()
            ->where('workspace_id', $workspace->id)
            ->where('id', $documentTypeId)
            ->firstOrFail();
    }

    /**
     * Filter the given tag ids down to those that actually belong to the workspace.
     *
     * Silently drops the ones that do not, rather than failing: a tag is a
     * label, not an instruction, and the useful answer to "file it under these
     * five, one of which is not yours" is the four that are.
     *
     * @param Workspace $workspace The workspace tags must belong to.
     * @param array<int, string> $tagIds Candidate tag UUIDs, e.g. from client input.
     *
     * @return array<int, string> The subset of $tagIds that exist and belong to $workspace.
     */
    protected function scopedTagIds(Workspace $workspace, array $tagIds): array
    {
        return Tag::query()
            ->where('workspace_id', $workspace->id)
            ->whereIn('id', $tagIds)
            ->pluck('id')
            ->all();
    }

    /**
     * Resolve an organization node by id, scoped to the given workspace.
     *
     * A node hangs off a level, which hangs off a scheme, which is the thing
     * that carries the workspace — hence the reach through the relation rather
     * than a column on the node itself.
     *
     * @param Workspace $workspace The workspace the node must belong to.
     * @param string $nodeId The UUID of the requested node.
     *
     * @return OrganizationNode The matching node.
     *
     * @throws ModelNotFoundException If no node with $nodeId exists within $workspace.
     */
    protected function scopedNode(Workspace $workspace, string $nodeId): OrganizationNode
    {
        return OrganizationNode::query()
            ->whereHas('level.scheme', fn ($query) => $query->where('workspace_id', $workspace->id))
            ->where('id', $nodeId)
            ->firstOrFail();
    }

    /**
     * Resolve an organization scheme by id, scoped to the given workspace.
     *
     * @param Workspace $workspace The workspace the scheme must belong to.
     * @param string $schemeId The UUID of the requested scheme.
     *
     * @return OrganizationScheme The matching scheme.
     *
     * @throws ModelNotFoundException If no scheme with $schemeId exists within $workspace.
     */
    protected function scopedScheme(Workspace $workspace, string $schemeId): OrganizationScheme
    {
        return OrganizationScheme::query()
            ->where('workspace_id', $workspace->id)
            ->where('id', $schemeId)
            ->firstOrFail();
    }
}
