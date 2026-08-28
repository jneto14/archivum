<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspaces;

use App\Actions\Workspace\CalculateWorkspaceUsage;
use App\Http\Controllers\Controller;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Inertia\Inertia;
use Inertia\Response;

class WorkspaceUsageController extends Controller
{
    /**
     * Show the workspace's current resource usage alongside its
     * configured limits.
     *
     * @param Workspace $workspace The workspace to report usage for.
     * @param CalculateWorkspaceUsage $action Computes the workspace's current storage, user, document, and attachment counts.
     *
     * @return Response The "Usage & limits" Inertia page.
     *
     * @throws AuthorizationException If the current user cannot view $workspace's usage.
     */
    public function show(Workspace $workspace, CalculateWorkspaceUsage $action): Response
    {
        $this->authorize('viewUsage', $workspace);

        $usage = $action->handle($workspace);
        $limits = $workspace->limits;

        return Inertia::render('workspace/usage', [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'usage' => [
                'storage' => ['used' => $usage['storage_bytes'], 'limit' => $limits?->storage_bytes],
                'users' => ['used' => $usage['users'], 'limit' => $limits?->users],
                'documents' => ['used' => $usage['documents'], 'limit' => $limits?->documents],
                'attachments' => ['used' => $usage['attachments'], 'limit' => $limits?->attachments],
            ],
        ]);
    }
}
