<?php

declare(strict_types=1);

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\StoreTagRequest;
use App\Http\Requests\Documents\UpdateTagRequest;
use App\Models\Tag;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

class TagController extends Controller
{
    /**
     * List the tags defined in the given workspace.
     *
     * @param Workspace $workspace The workspace whose tags are listed.
     *
     * @return Response The rendered tags page.
     *
     * @throws AuthorizationException If the current user isn't a member of $workspace.
     */
    public function index(Workspace $workspace): Response
    {
        $this->authorize('viewAny', [Tag::class, $workspace]);

        $tags = Tag::query()
            ->where('workspace_id', $workspace->id)
            ->withCount('documents')
            ->orderBy('name')
            ->get();

        return Inertia::render('tags/index', [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'tags' => $tags->map(fn (Tag $tag) => [
                'id' => $tag->id,
                'name' => $tag->name,
                'documents_count' => $tag->documents_count,
            ])->values()->all(),
        ]);
    }

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

        Inertia::flash('toast', ['type' => 'success', 'message' => __('document.tag_created')]);

        return back();
    }

    /**
     * Rename an existing tag.
     *
     * @param UpdateTagRequest $request The incoming request with the validated tag name.
     * @param Tag $tag The tag being renamed.
     *
     * @return RedirectResponse Redirect back to the previous page.
     *
     * @throws AuthorizationException If the current user cannot update $tag.
     */
    public function update(UpdateTagRequest $request, Tag $tag): RedirectResponse
    {
        $this->authorize('update', $tag);

        $tag->update(['name' => $request->validated('name')]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('document.tag_updated')]);

        return back();
    }

    /**
     * Delete a tag, detaching it from any documents it was applied to.
     *
     * @param Tag $tag The tag to delete.
     *
     * @return RedirectResponse Redirect back to the previous page.
     *
     * @throws AuthorizationException If the current user cannot delete $tag.
     */
    public function destroy(Tag $tag): RedirectResponse
    {
        $this->authorize('delete', $tag);

        $tag->delete();

        Inertia::flash('toast', ['type' => 'success', 'message' => __('document.tag_deleted')]);

        return back();
    }
}
