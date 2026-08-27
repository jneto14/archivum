<?php

namespace App\Http\Controllers\Workspaces;

use App\Actions\Workspace\CalculateWorkspaceUsage;
use App\Http\Controllers\Controller;
use App\Models\Workspace;
use Illuminate\Http\JsonResponse;

class WorkspaceUsageController extends Controller
{
    /**
     * Return the workspace's current resource usage alongside its
     * configured limits.
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
