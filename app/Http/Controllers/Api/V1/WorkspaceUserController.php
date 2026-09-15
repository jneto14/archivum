<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Workspace\AddWorkspaceUser;
use App\Actions\Workspace\ChangeWorkspaceUserRole;
use App\Actions\Workspace\FindOrCreateInvitedUser;
use App\Actions\Workspace\RemoveWorkspaceUser;
use App\Enums\WorkspaceRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\AddWorkspaceUserRequest;
use App\Http\Requests\Workspaces\UpdateWorkspaceUserRequest;
use App\Http\Resources\Api\V1\WorkspaceMemberResource;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class WorkspaceUserController extends Controller
{
    /**
     * List the workspace's members.
     *
     * @param Workspace $workspace The workspace whose members are listed.
     *
     * @return AnonymousResourceCollection The memberships, oldest first.
     *
     * @throws AuthorizationException If the token's user cannot see $workspace's members.
     */
    public function index(Workspace $workspace): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [WorkspaceUser::class, $workspace]);

        return WorkspaceMemberResource::collection(
            WorkspaceUser::query()
                ->where('workspace_id', $workspace->id)
                ->with('user')
                ->oldest()
                ->get(),
        );
    }

    /**
     * Add somebody to the workspace, inviting them by email if they have no
     * account yet.
     *
     * The invitation is the same one the interface sends, so a person added
     * this way arrives through the ordinary flow rather than with a password
     * somebody had to choose for them.
     *
     * @param AddWorkspaceUserRequest $request The incoming request with the validated email, name and role.
     * @param Workspace $workspace The workspace the user is added to.
     * @param FindOrCreateInvitedUser $findOrCreateUser Finds the user by email, or creates and invites a new one.
     * @param AddWorkspaceUser $action Attaches the resolved user with the given role.
     *
     * @return JsonResponse The new membership, with status 201.
     *
     * @throws AuthorizationException If the token's user cannot add members to $workspace.
     * @throws ValidationException If the workspace has reached its user limit.
     */
    public function store(
        AddWorkspaceUserRequest $request,
        Workspace $workspace,
        FindOrCreateInvitedUser $findOrCreateUser,
        AddWorkspaceUser $action,
    ): JsonResponse {
        $this->authorize('create', [WorkspaceUser::class, $workspace]);

        $target = $findOrCreateUser->handle(
            $request->validated('email'),
            $request->validated('name'),
            $workspace,
        );

        $action->handle($workspace, $target, WorkspaceRole::from($request->validated('role')));

        return (new WorkspaceMemberResource($this->membership($workspace, $target)))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Change a member's role.
     *
     * @param UpdateWorkspaceUserRequest $request The incoming request with the validated role.
     * @param Workspace $workspace The workspace the membership belongs to.
     * @param User $targetUser The member whose role is changing.
     * @param ChangeWorkspaceUserRole $action Applies the change.
     *
     * @return WorkspaceMemberResource The membership, with its new role.
     *
     * @throws ModelNotFoundException If $targetUser is not a member of $workspace.
     * @throws AuthorizationException If the token's user cannot change this membership.
     * @throws ValidationException If the change would demote the workspace's last remaining admin.
     */
    public function update(
        UpdateWorkspaceUserRequest $request,
        Workspace $workspace,
        User $targetUser,
        ChangeWorkspaceUserRole $action,
    ): WorkspaceMemberResource {
        $this->authorize('update', $this->membership($workspace, $targetUser));

        $action->handle($workspace, $targetUser, WorkspaceRole::from($request->validated('role')));

        return new WorkspaceMemberResource($this->membership($workspace, $targetUser));
    }

    /**
     * Remove a member from the workspace.
     *
     * @param Workspace $workspace The workspace the membership belongs to.
     * @param User $targetUser The member being removed.
     * @param RemoveWorkspaceUser $action Removes the membership.
     *
     * @return JsonResponse An empty 204.
     *
     * @throws ModelNotFoundException If $targetUser is not a member of $workspace.
     * @throws AuthorizationException If the token's user cannot remove this membership.
     * @throws ValidationException If removing $targetUser would leave $workspace without an admin.
     */
    public function destroy(Workspace $workspace, User $targetUser, RemoveWorkspaceUser $action): JsonResponse
    {
        $this->authorize('delete', $this->membership($workspace, $targetUser));

        $action->handle($workspace, $targetUser);

        return new JsonResponse(status: 204);
    }

    /**
     * Find the membership linking the workspace and the target user.
     *
     * @param Workspace $workspace The workspace.
     * @param User $targetUser The user whose membership is wanted.
     *
     * @return WorkspaceUser The membership, with its user loaded.
     *
     * @throws ModelNotFoundException If $targetUser is not a member of $workspace.
     */
    private function membership(Workspace $workspace, User $targetUser): WorkspaceUser
    {
        return WorkspaceUser::query()
            ->where('workspace_id', $workspace->id)
            ->where('user_id', $targetUser->id)
            ->with('user')
            ->firstOrFail();
    }
}
