<?php

namespace App\Http\Controllers\Workspaces;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class WorkspaceSwitchController extends Controller
{
    /**
     * Switch the session's current workspace, when multi-workspace support
     * is enabled.
     *
     * @param  Request  $request  The incoming request; its session is updated with the new current workspace.
     * @param  Workspace  $workspace  The workspace to switch to.
     * @return RedirectResponse Redirect back to the previous page.
     *
     * @throws NotFoundHttpException If multi-workspace support is disabled.
     * @throws AuthorizationException If the current user cannot view $workspace.
     */
    public function store(Request $request, Workspace $workspace): RedirectResponse
    {
        abort_unless(config('archivum.multi_workspace_enabled'), 404);

        $this->authorize('view', $workspace);

        $request->session()->put('current_workspace_id', $workspace->id);

        return back();
    }
}
