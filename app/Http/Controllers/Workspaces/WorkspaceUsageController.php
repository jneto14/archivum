<?php

namespace App\Http\Controllers\Workspaces;

use App\Actions\Workspace\CalculateWorkspaceUsage;
use App\Http\Controllers\Controller;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;

class WorkspaceUsageController extends Controller
{
    /**
     * Return the workspace's current resource usage alongside its
     * configured limits.
     *
     * @param  Workspace  $workspace  The workspace to report usage for.
     * @param  CalculateWorkspaceUsage  $action  Computes the workspace's current storage, user, document, and attachment counts.
     * @return JsonResponse The usage and limit figures for storage, users, documents, and attachments.
     *
     * @throws AuthorizationException If the current user cannot view $workspace's usage.
     */
    public function show(Workspace $workspace, CalculateWorkspaceUsage $action): JsonResponse
    {
        $this->authorize('viewUsage', $workspace);

        $usage = $action->handle($workspace);
        $limits = $workspace->limits;

        return response()->json([
            'storage' => ['used' => $usage['storage_bytes'], 'limit' => $limits?->storage_bytes],
            'users' => ['used' => $usage['users'], 'limit' => $limits?->users],
            'documents' => ['used' => $usage['documents'], 'limit' => $limits?->documents],
            'attachments' => ['used' => $usage['attachments'], 'limit' => $limits?->attachments],
        ]);
    }
}
