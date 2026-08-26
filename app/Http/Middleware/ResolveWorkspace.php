<?php

namespace App\Http\Middleware;

use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveWorkspace
{
    /**
     * Resolve the current Workspace for this request and make it available
     * via the `workspace` request attribute.
     *
     * Membership is re-validated on every request rather than trusted from
     * the session, so a user removed from a workspace mid-session loses
     * access on their very next request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! config('archivum.multi_workspace_enabled')) {
            $workspace = Workspace::query()->first();

            abort_if($workspace === null, 500, 'No workspace configured.');
            abort_unless($workspace->isMember($user), 403);
        } else {
            $workspaceId = $request->session()->get('current_workspace_id');

            $workspace = $workspaceId
                ? Workspace::query()
                    ->whereKey($workspaceId)
                    ->whereHas('users', fn ($query) => $query->whereKey($user->id))
                    ->first()
                : null;

            if ($workspace === null) {
                $workspace = $user->workspaces()->orderBy('workspace_user.created_at')->first();

                abort_if($workspace === null, 403, 'You are not a member of any workspace.');
            }

            $request->session()->put('current_workspace_id', $workspace->id);
        }

        $request->attributes->set('workspace', $workspace);

        return $next($request);
    }
}
