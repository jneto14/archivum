<?php

namespace App\Http\Controllers\Organization;

use App\Actions\Organization\CreateOrganizationNode;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StoreOrganizationNodeRequest;
use App\Models\OrganizationNode;
use App\Models\OrganizationScheme;
use Illuminate\Http\RedirectResponse;

class OrganizationNodeController extends Controller
{
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
