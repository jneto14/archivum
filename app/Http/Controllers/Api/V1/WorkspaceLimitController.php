<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Workspace\CalculateWorkspaceUsage;
use App\Actions\Workspace\UpdateWorkspaceLimit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\UpdateWorkspaceLimitRequest;
use App\Http\Resources\Api\V1\WorkspaceUsageResource;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;

class WorkspaceLimitController extends Controller
{
    /**
     * Set a workspace's ceilings.
     *
     * A null on any field means no limit at all, which is why the values are
     * passed through as null rather than folded into zero.
     *
     * @param UpdateWorkspaceLimitRequest $request The incoming request with the validated limits.
     * @param Workspace $workspace The workspace whose limits are set.
     * @param UpdateWorkspaceLimit $action Persists the limits.
     * @param CalculateWorkspaceUsage $usage Totals what the workspace uses, so the answer carries both halves.
     *
     * @return WorkspaceUsageResource The workspace's usage against its new limits.
     *
     * @throws AuthorizationException If the token's user cannot set $workspace's limits.
     */
    public function update(
        UpdateWorkspaceLimitRequest $request,
        Workspace $workspace,
        UpdateWorkspaceLimit $action,
        CalculateWorkspaceUsage $usage,
    ): WorkspaceUsageResource {
        $this->authorize('updateLimits', $workspace);

        $action->handle($workspace, [
            'storage_bytes' => $request->validated('storage_bytes') !== null ? (int) $request->validated('storage_bytes') : null,
            'users' => $request->validated('users') !== null ? (int) $request->validated('users') : null,
            'documents' => $request->validated('documents') !== null ? (int) $request->validated('documents') : null,
            'attachments' => $request->validated('attachments') !== null ? (int) $request->validated('attachments') : null,
        ]);

        $usage->forget($workspace);

        return new WorkspaceUsageResource($workspace->refresh(), $usage->handle($workspace));
    }
}
