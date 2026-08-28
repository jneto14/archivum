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

        $action->handle($workspace, $this->limitsFromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('workspace.limits_updated')]);

        return back();
    }

    /**
     * Normalize the request's raw limit fields into the shape expected by UpdateWorkspaceLimit.
     *
     * @param UpdateWorkspaceLimitRequest $request The incoming request holding the validated limit fields.
     *
     * @return array{storage_bytes: int|null, users: int|null, documents: int|null, attachments: int|null} The normalized limit values.
     */
    private function limitsFromRequest(UpdateWorkspaceLimitRequest $request): array
    {
        return [
            'storage_bytes' => $request->validated('storage_bytes') !== null ? (int) $request->validated('storage_bytes') : null,
            'users' => $request->validated('users') !== null ? (int) $request->validated('users') : null,
            'documents' => $request->validated('documents') !== null ? (int) $request->validated('documents') : null,
            'attachments' => $request->validated('attachments') !== null ? (int) $request->validated('attachments') : null,
        ];
    }
}
