<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspaces;

use App\Actions\Workspace\CalculateWorkspaceUsage;
use App\Actions\Workspace\CreateWorkspace;
use App\Actions\Workspace\UpdateWorkspace;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\StoreWorkspaceRequest;
use App\Http\Requests\Workspaces\UpdateWorkspaceRequest;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceController extends Controller
{
    /**
     * Show a workspace's overview, including its current resource usage
     * against its configured limits for admins.
     *
     * @param Request $request The incoming request, used to resolve the acting user.
     * @param Workspace $workspace The workspace being viewed.
     * @param CalculateWorkspaceUsage $action Computes the workspace's current storage, user, document, and attachment counts.
     *
     * @return Response The rendered workspace overview page.
     *
     * @throws AuthorizationException If the current user isn't a member of $workspace.
     */
    public function show(Request $request, Workspace $workspace, CalculateWorkspaceUsage $action): Response
    {
        $this->authorize('view', $workspace);

        $isAdmin = $workspace->isAdmin($request->user());

        return Inertia::render('workspace/show', [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'isAdmin' => $isAdmin,
            'usage' => $isAdmin ? $this->usage($workspace, $action) : null,
        ]);
    }

    /**
     * Create a new workspace and switch the current session to it.
     *
     * @param StoreWorkspaceRequest $request The incoming request with the validated workspace name.
     * @param CreateWorkspace $action Creates the workspace and attaches the current user as its admin.
     *
     * @return RedirectResponse Redirect back to the previous page.
     *
     * @throws AuthorizationException If the current user cannot create workspaces.
     */
    public function store(StoreWorkspaceRequest $request, CreateWorkspace $action): RedirectResponse
    {
        $this->authorize('create', Workspace::class);

        $workspace = $action->handle($request->user(), $request->validated('name'));

        $request->session()->put('current_workspace_id', $workspace->id);

        return back();
    }

    /**
     * Update a workspace's attributes.
     *
     * @param UpdateWorkspaceRequest $request The incoming request with the validated workspace name.
     * @param Workspace $workspace The workspace being updated.
     * @param UpdateWorkspace $action Applies the update.
     *
     * @return RedirectResponse Redirect back to the previous page.
     *
     * @throws AuthorizationException If the current user cannot update $workspace.
     */
    public function update(UpdateWorkspaceRequest $request, Workspace $workspace, UpdateWorkspace $action): RedirectResponse
    {
        $this->authorize('update', $workspace);

        $action->handle($workspace, $request->validated('name'));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('workspace.updated')]);

        return back();
    }

    /**
     * Compute a workspace's current usage figures alongside its configured limits.
     *
     * @param Workspace $workspace The workspace to report usage for.
     * @param CalculateWorkspaceUsage $action Computes the workspace's current storage, user, document, and attachment counts.
     *
     * @return array{storage: array{used: int, limit: int|null}, users: array{used: int, limit: int|null}, documents: array{used: int, limit: int|null}, attachments: array{used: int, limit: int|null}} The usage and limit figures for storage, users, documents, and attachments.
     */
    private function usage(Workspace $workspace, CalculateWorkspaceUsage $action): array
    {
        $usage = $action->handle($workspace);
        $limits = $workspace->limits;

        return [
            'storage' => ['used' => $usage['storage_bytes'], 'limit' => $limits?->storage_bytes],
            'users' => ['used' => $usage['users'], 'limit' => $limits?->users],
            'documents' => ['used' => $usage['documents'], 'limit' => $limits?->documents],
            'attachments' => ['used' => $usage['attachments'], 'limit' => $limits?->attachments],
        ];
    }
}
