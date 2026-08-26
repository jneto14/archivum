<?php

namespace App\Http\Controllers\Workspaces;

use App\Http\Controllers\Controller;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class WorkspaceSwitchController extends Controller
{
    public function store(Request $request, Workspace $workspace): RedirectResponse
    {
        abort_unless(config('archivum.multi_workspace_enabled'), 404);

        $this->authorize('view', $workspace);

        $request->session()->put('current_workspace_id', $workspace->id);

        return back();
    }
}
