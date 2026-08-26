<?php

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\StoreTagRequest;
use App\Models\Tag;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;

class TagController extends Controller
{
    public function store(StoreTagRequest $request, Workspace $workspace): RedirectResponse
    {
        $this->authorize('create', [Tag::class, $workspace]);

        Tag::query()->create([
            'workspace_id' => $workspace->id,
            'name' => $request->validated('name'),
        ]);

        return back();
    }
}
