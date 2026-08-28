<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspaces;

use App\Actions\Workspace\UpdateWorkspaceLimit;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\UpdateWorkspaceLimitRequest;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;

class WorkspaceLimitController extends Controller
{
    /**
     * Update a workspace's configured resource limits. Restricted to platform
     * admins — the "Usage & limits" page is otherwise a read-only view for
     * workspace admins, not a place to manage the workspace itself.
     *
     * @param UpdateWorkspaceLimitRequest $request The incoming request with the validated limit values.
     * @param Workspace $workspace The workspace whose limits are being updated.
     * @param UpdateWorkspaceLimit $action Creates or updates the workspace's limit record.
     *
     * @return RedirectResponse Redirect back to the previous page.
     *
     * @throws AuthorizationException If the current user cannot edit $workspace's limits.
     */
    public function update(UpdateWorkspaceLimitRequest $request, Workspace $workspace, UpdateWorkspaceLimit $action): RedirectResponse
    {
        $this->authorize('updateLimits', $workspace);

        $action->handle($workspace, $request->validated());

        Inertia::flash('toast', ['type' => 'success', 'message' => __('workspace.limits_updated')]);

        return back();
    }
}
