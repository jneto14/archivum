<?php

declare(strict_types=1);

namespace App\Http\Controllers\Documents;

use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\StoreTagRequest;
use App\Http\Requests\Documents\UpdateTagRequest;
use App\Models\DocumentTag;
use App\Models\Tag;
use App\Models\Workspace;
use App\Support\TableSort;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Inertia\Inertia;
use Inertia\Response;

class TagController extends Controller
{
    /**
     * List the tags defined in the given workspace.
     *
     * @param Request $request The incoming request, read for the chosen order.
     * @param Workspace $workspace The workspace whose tags are listed.
     *
     * @return Response The rendered tags page.
     *
     * @throws AuthorizationException If the current user isn't a member of $workspace.
     */
    public function index(Request $request, Workspace $workspace): Response
    {
        $this->authorize('viewAny', [Tag::class, $workspace]);

        $sort = TableSort::fromRequest($request, [
            'name' => 'tags.name',
            'documents_count' => 'documents_count',
            'last_used_at' => 'last_used_at',
        ], 'name');

        $tags = Tag::query()
            ->where('workspace_id', $workspace->id)
            ->withCount('documents')
            // Selected rather than fetched separately and merged in PHP, which
            // is how it used to work: a value assembled after the query cannot
            // be ordered by, and this is one of the three things the list offers
            // to sort on. It also spends one query fewer.
            ->addSelect(['last_used_at' => DocumentTag::query()
                ->selectRaw('max(created_at)')
                ->whereColumn('tag_id', 'tags.id'),
            ])
            ->tap(fn (Builder $query) => $sort->apply($query, 'tags.id'))
            ->get();

        return Inertia::render('tags/index', [
            'workspace' => ['id' => $workspace->id, 'name' => $workspace->name],
            'sort' => $sort->toArray(),
            'tags' => $tags->map(fn (Tag $tag) => [
                'id' => $tag->id,
                'name' => $tag->name,
                'documents_count' => $tag->documents_count,
                // Read off the query rather than the model: the column is
                // selected by this listing and is not part of a tag.
                'last_used_at' => ($lastUsed = $tag->getAttribute('last_used_at')) !== null
                    ? Carbon::parse($lastUsed)->toISOString()
                    : null,
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
