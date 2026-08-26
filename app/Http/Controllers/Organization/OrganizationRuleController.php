<?php

namespace App\Http\Controllers\Organization;

use App\Actions\Organization\CreateOrganizationRule;
use App\Actions\Organization\UpdateOrganizationRule;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StoreOrganizationRuleRequest;
use App\Http\Requests\Organization\UpdateOrganizationRuleRequest;
use App\Models\OrganizationRule;
use App\Models\OrganizationScheme;
use Illuminate\Http\RedirectResponse;

class OrganizationRuleController extends Controller
{
    public function store(StoreOrganizationRuleRequest $request, OrganizationScheme $scheme, CreateOrganizationRule $action): RedirectResponse
    {
        $this->authorize('update', $scheme);

        $targetLevel = $scheme->levels()->where('id', $request->validated('target_level_id'))->firstOrFail();

        $action->handle(
            $scheme,
            $request->validated('matcher_key'),
            $request->validated('matcher_value'),
            $targetLevel,
            $request->validated('preferred_value'),
        );

        return back();
    }

    public function update(UpdateOrganizationRuleRequest $request, OrganizationScheme $scheme, OrganizationRule $rule, UpdateOrganizationRule $action): RedirectResponse
    {
        $this->authorize('update', $scheme);

        abort_unless($rule->scheme_id === $scheme->id, 404);

        $targetLevel = $scheme->levels()->where('id', $request->validated('target_level_id'))->firstOrFail();

        $action->handle(
            $rule,
            $request->validated('matcher_key'),
            $request->validated('matcher_value'),
            $targetLevel,
            $request->validated('preferred_value'),
        );

        return back();
    }

    public function destroy(OrganizationScheme $scheme, OrganizationRule $rule): RedirectResponse
    {
        $this->authorize('update', $scheme);

        abort_unless($rule->scheme_id === $scheme->id, 404);

        $rule->delete();

        return back();
    }
}
