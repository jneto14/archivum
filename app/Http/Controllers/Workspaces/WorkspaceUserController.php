<?php

namespace App\Http\Controllers\Workspaces;

use App\Actions\Workspace\AddWorkspaceUser;
use App\Actions\Workspace\ChangeWorkspaceUserRole;
use App\Actions\Workspace\FindOrCreateInvitedUser;
use App\Actions\Workspace\RemoveWorkspaceUser;
use App\Enums\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\AddWorkspaceUserRequest;
use App\Http\Requests\Workspaces\UpdateWorkspaceUserRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Http\RedirectResponse;

class WorkspaceUserController extends Controller
{
    public function store(AddWorkspaceUserRequest $request, Workspace $workspace, FindOrCreateInvitedUser $findOrCreateUser, AddWorkspaceUser $action): RedirectResponse
    {
        $this->authorize('create', [WorkspaceUser::class, $workspace]);

        $target = $findOrCreateUser->handle($request->validated('email'), $request->validated('name'), $workspace);

        $action->handle($workspace, $target, WorkspaceRole::from($request->validated('role')));

        return back();
    }

    public function update(UpdateWorkspaceUserRequest $request, Workspace $workspace, User $targetUser, ChangeWorkspaceUserRole $action): RedirectResponse
    {
        $membership = $this->membership($workspace, $targetUser);

        $this->authorize('update', $membership);

        $action->handle($workspace, $targetUser, WorkspaceRole::from($request->validated('role')));

        return back();
    }

    public function destroy(Workspace $workspace, User $targetUser, RemoveWorkspaceUser $action): RedirectResponse
    {
        $membership = $this->membership($workspace, $targetUser);

        $this->authorize('delete', $membership);

        $action->handle($workspace, $targetUser);

        return back();
    }

    /**
     * Find the membership record linking the workspace and target user.
     */
    private function membership(Workspace $workspace, User $targetUser): WorkspaceUser
    {
        return WorkspaceUser::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $targetUser->id)
            ->firstOrFail();
    }
}
