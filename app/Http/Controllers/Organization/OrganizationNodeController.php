<?php

namespace App\Http\Controllers\Organization;

use App\Actions\Organization\CreateOrganizationNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StoreOrganizationNodeRequest;
use App\Models\OrganizationNode;
use App\Models\OrganizationScheme;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class OrganizationNodeController extends Controller
{
    /**
     * Create a node under the given scheme's level, optionally nested under
     * a parent node belonging to the same scheme.
     *
     * @param  StoreOrganizationNodeRequest  $request  The incoming request with the validated level, parent, and value.
     * @param  OrganizationScheme  $scheme  The scheme the new node belongs to.
     * @param  CreateOrganizationNode  $action  Creates the node, auto-generating its value when applicable.
     * @return RedirectResponse Redirect back to the previous page.
     *
     * @throws AuthorizationException If the current user cannot update $scheme.
     * @throws ModelNotFoundException If the requested level, or parent node, does not belong to $scheme.
     * @throws ValidationException If the level's capacity is reached, a manual value is required but missing, the parent is inconsistent with the level, or the value is already taken among its siblings.
     */
    public function store(StoreOrganizationNodeRequest $request, OrganizationScheme $scheme, CreateOrganizationNode $action): RedirectResponse
    {
        $this->authorize('update', $scheme);

        $level = $scheme->levels()->where('id', $request->validated('level_id'))->firstOrFail();

        $parentId = $request->validated('parent_id');
        $parent = $parentId !== null
            ? OrganizationNode::query()
                ->whereHas('level', fn ($query) => $query->where('scheme_id', $scheme->id))
                ->where('id', $parentId)
                ->firstOrFail()
            : null;

        $action->handle($level, $parent, $request->validated('value'));

        return back();
    }
}
