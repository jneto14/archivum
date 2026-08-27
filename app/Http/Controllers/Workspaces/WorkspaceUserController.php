<?php

declare(strict_types=1);

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
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class WorkspaceUserController extends Controller
{
    /**
     * Add a user to the workspace, inviting them by email if they don't already have an account.
     *
     * @param AddWorkspaceUserRequest $request The incoming request with the validated email, name, and role.
     * @param Workspace $workspace The workspace the user is added to.
     * @param FindOrCreateInvitedUser $findOrCreateUser Finds the user by email, or creates and invites a new one.
     * @param AddWorkspaceUser $action Attaches the resolved user to $workspace with the given role.
     *
     * @return RedirectResponse Redirect back to the previous page.
     *
     * @throws AuthorizationException If the current user cannot add members to $workspace.
     * @throws ValidationException If the target user is already a member, or the workspace's user limit would be exceeded.
     */
    public function store(AddWorkspaceUserRequest $request, Workspace $workspace, FindOrCreateInvitedUser $findOrCreateUser, AddWorkspaceUser $action): RedirectResponse
    {
        $this->authorize('create', [WorkspaceUser::class, $workspace]);

        $target = $findOrCreateUser->handle($request->validated('email'), $request->validated('name'), $workspace);

        $action->handle($workspace, $target, WorkspaceRole::from($request->validated('role')));

        return back();
    }

    /**
     * Change a member's role within the workspace.
     *
     * @param UpdateWorkspaceUserRequest $request The incoming request with the validated new role.
     * @param Workspace $workspace The workspace the membership belongs to.
     * @param User $targetUser The member whose role is being changed.
     * @param ChangeWorkspaceUserRole $action Applies the role change.
     *
     * @return RedirectResponse Redirect back to the previous page.
     *
     * @throws ModelNotFoundException If $targetUser is not a member of $workspace.
     * @throws AuthorizationException If the current user cannot update the membership.
     * @throws ValidationException If the change would demote the workspace's last remaining admin.
     */
    public function update(UpdateWorkspaceUserRequest $request, Workspace $workspace, User $targetUser, ChangeWorkspaceUserRole $action): RedirectResponse
    {
        $membership = $this->membership($workspace, $targetUser);

        $this->authorize('update', $membership);

        $action->handle($workspace, $targetUser, WorkspaceRole::from($request->validated('role')));

        return back();
    }

    /**
     * Remove a member from the workspace.
     *
     * @param Workspace $workspace The workspace the membership belongs to.
     * @param User $targetUser The member being removed.
     * @param RemoveWorkspaceUser $action Removes the membership.
     *
     * @return RedirectResponse Redirect back to the previous page.
     *
     * @throws ModelNotFoundException If $targetUser is not a member of $workspace.
     * @throws AuthorizationException If the current user cannot delete the membership.
     * @throws ValidationException If removing $targetUser would leave $workspace without any admin.
     */
    public function destroy(Workspace $workspace, User $targetUser, RemoveWorkspaceUser $action): RedirectResponse
    {
        $membership = $this->membership($workspace, $targetUser);

        $this->authorize('delete', $membership);

        $action->handle($workspace, $targetUser);

        return back();
    }

    /**
     * Find the membership record linking the workspace and target user.
     *
     * @param Workspace $workspace The workspace the membership must belong to.
     * @param User $targetUser The user the membership must belong to.
     *
     * @return WorkspaceUser The matching membership record.
     *
     * @throws ModelNotFoundException If $targetUser is not a member of $workspace.
     */
    private function membership(Workspace $workspace, User $targetUser): WorkspaceUser
    {
        return WorkspaceUser::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $targetUser->id)
            ->firstOrFail();
    }
}
