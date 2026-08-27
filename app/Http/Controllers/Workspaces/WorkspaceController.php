<?php

namespace App\Http\Controllers\Workspaces;

use App\Actions\Workspace\CreateWorkspace;
use App\Actions\Workspace\UpdateWorkspace;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\StoreWorkspaceRequest;
use App\Http\Requests\Workspaces\UpdateWorkspaceRequest;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;

class WorkspaceController extends Controller
{
    /**
     * Create a new workspace and switch the current session to it.
     */
    public function store(StoreWorkspaceRequest $request, CreateWorkspace $action): RedirectResponse
    {
        $this->authorize('create', Workspace::class);

        $workspace = $action->handle($request->user(), $request->validated('name'));

        $request->session()->put('current_workspace_id', $workspace->id);

        return back();
    }

    public function update(UpdateWorkspaceRequest $request, Workspace $workspace, UpdateWorkspace $action): RedirectResponse
    {
        $this->authorize('update', $workspace);

        $action->handle($workspace, $request->validated('name'));

        return back();
    }
}
