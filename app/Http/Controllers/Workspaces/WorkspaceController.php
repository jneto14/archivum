<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspaces;

use App\Actions\Workspace\CreateWorkspace;
use App\Actions\Workspace\DeleteWorkspace;
use App\Actions\Workspace\UpdateWorkspace;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\StoreWorkspaceRequest;
use App\Http\Requests\Workspaces\UpdateWorkspaceRequest;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceController extends Controller
{
    /**
     * List every workspace on the instance, for platform admins to browse and
     * manage regardless of their own membership. Only meaningful in
     * multi-workspace mode — in single-workspace mode the whole instance is
     * one workspace, so there is nothing else to browse.
     *
     * @param Request $request The incoming request, used to resolve the acting user.
     *
     * @return Response The rendered workspace index page.
     */
    public function index(Request $request): Response
    {
        abort_unless(config('archivum.multi_workspace_enabled'), 404);
        abort_unless($request->user()->is_platform_admin, 403);

        return Inertia::render('workspace/index', [
            'workspaces' => Workspace::query()
                ->withCount('users')
                ->orderBy('name')
                ->get()
                ->map(fn (Workspace $workspace) => [
                    'id' => $workspace->id,
                    'name' => $workspace->name,
                    'usersCount' => $workspace->users_count,
                    'createdAtDiff' => $workspace->created_at?->diffForHumans(),
                ])->all(),
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
        abort_unless(config('archivum.multi_workspace_enabled'), 404);

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
     * Delete a workspace and all of its data.
     *
     * @param Workspace $workspace The workspace being deleted.
     * @param DeleteWorkspace $action Purges attachment files from disk, then deletes the workspace and cascades every dependent row.
     *
     * @return RedirectResponse Redirect to the dashboard, since $workspace no longer exists.
     *
     * @throws AuthorizationException If the current user cannot delete $workspace.
     * @throws ValidationException If $workspace is the only workspace in the instance.
     */
    public function destroy(Workspace $workspace, DeleteWorkspace $action): RedirectResponse
    {
        $this->authorize('delete', $workspace);

        $action->handle($workspace);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('workspace.deleted')]);

        return redirect()->route('dashboard');
    }
}
