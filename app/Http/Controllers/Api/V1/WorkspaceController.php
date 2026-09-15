<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Workspace\CalculateWorkspaceUsage;
use App\Actions\Workspace\CreateWorkspace;
use App\Actions\Workspace\DeleteWorkspace;
use App\Actions\Workspace\UpdateWorkspace;
use App\Http\Controllers\Controller;
use App\Http\Requests\Workspaces\StoreWorkspaceRequest;
use App\Http\Requests\Workspaces\UpdateWorkspaceRequest;
use App\Http\Resources\Api\V1\WorkspaceResource;
use App\Http\Resources\Api\V1\WorkspaceUsageResource;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Validation\ValidationException;

class WorkspaceController extends Controller
{
    /**
     * List the workspaces this token can reach.
     *
     * The first call any client makes, because every other route names a
     * workspace in its path and a token carries no default one.
     *
     * Deliberately not the interface's own workspace index, which is a
     * platform-admin screen listing every workspace on the instance. Here an
     * ordinary token gets its user's memberships; a platform admin, who may
     * resolve any workspace, gets all of them.
     *
     * @param Request $request The incoming request, used to resolve the token's user.
     *
     * @return AnonymousResourceCollection The workspaces the user belongs to, or all of them for a platform admin.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $workspaces = $user->is_platform_admin
            ? Workspace::query()->withCount('users')->orderBy('name')->get()
            : $user->workspaces()->withCount('users')->orderBy('name')->get();

        return WorkspaceResource::collection($workspaces);
    }

    /**
     * Read one workspace.
     *
     * @param Workspace $workspace The workspace being read.
     *
     * @return WorkspaceResource The workspace.
     *
     * @throws AuthorizationException If the token's user cannot view $workspace.
     */
    public function show(Workspace $workspace): WorkspaceResource
    {
        $this->authorize('view', $workspace);

        return new WorkspaceResource($workspace->loadCount('users'));
    }

    /**
     * Create a workspace.
     *
     * @param StoreWorkspaceRequest $request The incoming request with the validated name.
     * @param CreateWorkspace $action Creates the workspace and makes its creator an admin of it.
     *
     * @return JsonResponse The created workspace, with status 201.
     *
     * @throws AuthorizationException If the token's user cannot create workspaces.
     */
    public function store(StoreWorkspaceRequest $request, CreateWorkspace $action): JsonResponse
    {
        $this->authorize('create', Workspace::class);

        $workspace = $action->handle($request->user(), $request->validated('name'));

        return (new WorkspaceResource($workspace->loadCount('users')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Rename a workspace.
     *
     * @param UpdateWorkspaceRequest $request The incoming request with the validated name.
     * @param Workspace $workspace The workspace being renamed.
     * @param UpdateWorkspace $action Applies the rename.
     *
     * @return WorkspaceResource The renamed workspace.
     *
     * @throws AuthorizationException If the token's user cannot update $workspace.
     */
    public function update(UpdateWorkspaceRequest $request, Workspace $workspace, UpdateWorkspace $action): WorkspaceResource
    {
        $this->authorize('update', $workspace);

        $action->handle($workspace, $request->validated('name'));

        return new WorkspaceResource($workspace->refresh()->loadCount('users'));
    }

    /**
     * Delete a workspace, and everything filed in it.
     *
     * Not reversible and not a trash: a workspace does not soft-delete, so
     * this unlinks every file it holds — including whatever was in its own
     * trash — and lets the database cascade take the rest (ARC-128).
     *
     * @param Workspace $workspace The workspace being deleted.
     * @param DeleteWorkspace $action Unlinks the files and deletes the workspace.
     *
     * @return JsonResponse An empty 204.
     *
     * @throws AuthorizationException If the token's user cannot delete $workspace.
     * @throws ValidationException If this is the only workspace on the instance.
     */
    public function destroy(Workspace $workspace, DeleteWorkspace $action): JsonResponse
    {
        $this->authorize('delete', $workspace);

        $action->handle($workspace);

        return new JsonResponse(status: 204);
    }

    /**
     * Report what the workspace is using, against what it is allowed.
     *
     * @param Workspace $workspace The workspace whose usage is reported.
     * @param CalculateWorkspaceUsage $action Totals the workspace's storage, users, documents and attachments.
     *
     * @return WorkspaceUsageResource Each metric's usage and ceiling.
     *
     * @throws AuthorizationException If the token's user cannot view $workspace's usage.
     */
    public function usage(Workspace $workspace, CalculateWorkspaceUsage $action): WorkspaceUsageResource
    {
        $this->authorize('viewUsage', $workspace);

        return new WorkspaceUsageResource($workspace, $action->handle($workspace));
    }
}
