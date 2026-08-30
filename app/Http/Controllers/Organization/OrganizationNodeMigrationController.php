<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organization;

use App\Actions\Organization\StartBulkDocumentMove;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\MigrateNodeDocumentsRequest;
use App\Models\OrganizationNode;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class OrganizationNodeMigrationController extends Controller
{
    /**
     * Queue a job that relocates every document currently located at $node onto the target node.
     *
     * @param MigrateNodeDocumentsRequest $request The incoming request with the validated target node id.
     * @param OrganizationNode $node The source node whose documents are being migrated.
     * @param StartBulkDocumentMove $action Acquires the move lock, creates the task, and dispatches the job.
     *
     * @return RedirectResponse Redirect back to the previous page.
     *
     * @throws AuthorizationException If the current user cannot manage $node's scheme.
     * @throws ModelNotFoundException If the target node does not exist in the same workspace as $node.
     * @throws ValidationException If the target node is the same as $node, or a bulk move is already running
     *                             for the workspace.
     */
    public function store(MigrateNodeDocumentsRequest $request, OrganizationNode $node, StartBulkDocumentMove $action): RedirectResponse
    {
        $this->authorize('update', $node->level->scheme);

        $target = $this->resolveTargetNode($node, $request->validated('target_node_id'));

        $action->handle($node, $target, $request->user());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('organization.migration_queued')]);

        return back();
    }

    /**
     * @param OrganizationNode $node The source node.
     * @param string $targetNodeId The id of the requested target node.
     *
     * @return OrganizationNode The resolved target node.
     *
     * @throws ModelNotFoundException If no node with $targetNodeId exists in $node's workspace.
     * @throws ValidationException If the resolved target node is the same as $node.
     */
    private function resolveTargetNode(OrganizationNode $node, string $targetNodeId): OrganizationNode
    {
        $target = OrganizationNode::query()
            ->whereHas('level.scheme', fn ($query) => $query->where('workspace_id', $node->level->scheme->workspace_id))
            ->where('id', $targetNodeId)
            ->firstOrFail();

        if ($target->id === $node->id) {
            throw ValidationException::withMessages([
                'target_node_id' => __('organization.migration_target_must_differ'),
            ]);
        }

        return $target;
    }
}
