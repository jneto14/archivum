<?php

declare(strict_types=1);

namespace App\Http\Controllers\Workspaces;

use App\Http\Controllers\Controller;
use App\Models\OrganizationScheme;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Sanctum\PersonalAccessToken;

class WorkspaceSettingsController extends Controller
{
    /**
     * Show a workspace's settings: its name, organization scheme, read-only
     * instance flags, the current user's API tokens, account links, and —
     * for platform admins only — its configured resource limits.
     *
     * @param Request $request The incoming request, used to resolve the acting user.
     * @param Workspace $workspace The workspace whose settings are being viewed.
     *
     * @return Response The rendered workspace settings page.
     *
     * @throws AuthorizationException If the current user cannot update $workspace.
     */
    public function show(Request $request, Workspace $workspace): Response
    {
        $this->authorize('update', $workspace);

        $scheme = OrganizationScheme::query()->where('workspace_id', $workspace->id)->first(['id', 'name']);
        $isPlatformAdmin = (bool) $request->user()->is_platform_admin;

        return Inertia::render('workspace/settings', [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'scheme' => $scheme !== null ? ['id' => $scheme->id, 'name' => $scheme->name] : null,
            'instance' => [
                'multi_workspace_enabled' => (bool) config('archivum.multi_workspace_enabled'),
                'attachments_disk' => config('archivum.attachments.disk'),
            ],
            'tokens' => $request->user()->tokens->map(fn (PersonalAccessToken $token) => [
                'id' => $token->id,
                'name' => $token->name,
                'created_at_diff' => $token->created_at?->diffForHumans(),
                'last_used_at_diff' => $token->last_used_at?->diffForHumans(),
            ])->values()->all(),
            'isPlatformAdmin' => $isPlatformAdmin,
            'limits' => $isPlatformAdmin ? [
                'storage_bytes' => $workspace->limits?->storage_bytes,
                'users' => $workspace->limits?->users,
                'documents' => $workspace->limits?->documents,
                'attachments' => $workspace->limits?->attachments,
            ] : null,
        ]);
    }
}
