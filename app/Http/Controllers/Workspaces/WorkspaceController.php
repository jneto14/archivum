<?php

namespace App\Http\Controllers\Workspaces;

use App\Actions\Workspace\CreateWorkspace;
use App\Actions\Workspace\UpdateWorkspace;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\StoreWorkspaceRequest;
use App\Http\Requests\Workspaces\UpdateWorkspaceRequest;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;

class WorkspaceController extends Controller
{
    /**
     * Create a new workspace and switch the current session to it.
     *
     * @param  StoreWorkspaceRequest  $request  The incoming request with the validated workspace name.
     * @param  CreateWorkspace  $action  Creates the workspace and attaches the current user as its admin.
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
     * @param  UpdateWorkspaceRequest  $request  The incoming request with the validated workspace name.
     * @param  Workspace  $workspace  The workspace being updated.
     * @param  UpdateWorkspace  $action  Applies the update.
     * @return RedirectResponse Redirect back to the previous page.
     *
     * @throws AuthorizationException If the current user cannot update $workspace.
     */
    public function update(UpdateWorkspaceRequest $request, Workspace $workspace, UpdateWorkspace $action): RedirectResponse
    {
        $this->authorize('update', $workspace);

        $action->handle($workspace, $request->validated('name'));

        return back();
    }
}
