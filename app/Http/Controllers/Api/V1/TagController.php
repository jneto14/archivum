<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Documents\StoreTagRequest;
use App\Http\Requests\Documents\UpdateTagRequest;
use App\Http\Resources\Api\V1\TagResource;
use App\Models\DocumentTag;
use App\Models\Tag;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TagController extends Controller
{
    /**
     * List the tags defined in the given workspace.
     *
     * Unpaginated for the same reason as the types, and carrying the same
     * usage figures the interface's own list does: how many documents wear a
     * tag, and when it was last applied. Both are what a client needs to
     * decide a tag has fallen out of use.
     *
     * @param Workspace $workspace The workspace whose tags are listed.
     *
     * @return AnonymousResourceCollection The workspace's tags, ordered by name.
     *
     * @throws AuthorizationException If the token's user isn't a member of $workspace.
     */
    public function index(Workspace $workspace): AnonymousResourceCollection
    {
        $this->authorize('viewAny', [Tag::class, $workspace]);

        return TagResource::collection(
            Tag::query()
                ->where('workspace_id', $workspace->id)
                ->withCount('documents')
                // Selected rather than fetched separately and merged, which is
                // what the interface's listing does and for the same reason:
                // a value assembled after the query costs an extra round trip
                // and cannot be ordered by.
                ->addSelect(['last_used_at' => DocumentTag::query()
                    ->selectRaw('max(created_at)')
                    ->whereColumn('tag_id', 'tags.id'),
                ])
                ->orderBy('name')
                ->get(),
        );
    }

    /**
     * Create a new tag within the given workspace.
     *
     * @param StoreTagRequest $request The incoming request with the validated tag name.
     * @param Workspace $workspace The workspace the tag is created in.
     *
     * @return JsonResponse The created tag, with status 201.
     *
     * @throws AuthorizationException If the token's user cannot create tags in $workspace.
     */
    public function store(StoreTagRequest $request, Workspace $workspace): JsonResponse
    {
        $this->authorize('create', [Tag::class, $workspace]);

        $tag = Tag::query()->create([
            'workspace_id' => $workspace->id,
            'name' => $request->validated('name'),
        ]);

        return (new TagResource($tag))->response()->setStatusCode(201);
    }

    /**
     * Rename an existing tag.
     *
     * @param UpdateTagRequest $request The incoming request with the validated tag name.
     * @param Tag $tag The tag being renamed.
     *
     * @return TagResource The renamed tag.
     *
     * @throws AuthorizationException If the token's user cannot update $tag.
     */
    public function update(UpdateTagRequest $request, Tag $tag): TagResource
    {
        $this->authorize('update', $tag);

        $tag->update(['name' => $request->validated('name')]);

        return new TagResource($tag);
    }

    /**
     * Delete a tag, detaching it from any documents it was applied to.
     *
     * Unlike a document type, a tag in use is not protected: losing a label is
     * not losing a filing, and the interface deletes it the same way.
     *
     * @param Tag $tag The tag to delete.
     *
     * @return JsonResponse An empty 204.
     *
     * @throws AuthorizationException If the token's user cannot delete $tag.
     */
    public function destroy(Tag $tag): JsonResponse
    {
        $this->authorize('delete', $tag);

        $tag->delete();

        return new JsonResponse(status: 204);
    }
}
