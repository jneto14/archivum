<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Workspace;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveWorkspace
{
    /**
     * Resolve the current Workspace for this request, if any, and make it
     * available via the `workspace` request attribute.
     *
     * Runs globally on every web request (guest and authenticated alike), so
     * it never aborts: a guest, or a user who isn't a member of any
     * workspace yet (e.g. pending an invite), simply gets a null attribute
     * and the rest of the pipeline decides what to do with that. Membership
     * is re-validated on every request rather than trusted from the
     * session, so a user removed from a workspace mid-session loses access
     * on their very next request.
     *
     * @param Request $request The incoming request; its session's `current_workspace_id` is read and updated when a workspace resolves.
     * @param Closure(Request): Response $next The next middleware/handler in the pipeline.
     *
     * @return Response The response produced by the rest of the pipeline.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        if (!config('archivum.multi_workspace_enabled')) {
            $workspace = Workspace::query()->first();
            $workspace = $workspace !== null && $workspace->isMember($user) ? $workspace : null;
        } else {
            $workspaceId = $request->session()->get('current_workspace_id');

            $workspace = $workspaceId
                ? Workspace::query()
                    ->whereKey($workspaceId)
                    ->whereHas('users', fn ($query) => $query->whereKey($user->id))
                    ->first()
                : null;

            $workspace ??= $user->workspaces()->orderBy('workspace_user.created_at')->first();

            if ($workspace !== null) {
                $request->session()->put('current_workspace_id', $workspace->id);
            }
        }

        $request->attributes->set('workspace', $workspace);

        return $next($request);
    }
}
