<?php

declare(strict_types=1);

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\StoreTagRequest;
use App\Models\Tag;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;

class TagController extends Controller
{
    /**
     * Create a new tag within the given workspace.
     *
     * @param StoreTagRequest $request The incoming request with the validated tag name.
     * @param Workspace $workspace The workspace the tag is created in.
     *
     * @return RedirectResponse Redirect back to the previous page.
     *
     * @throws AuthorizationException If the current user cannot create tags in $workspace.
     */
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
