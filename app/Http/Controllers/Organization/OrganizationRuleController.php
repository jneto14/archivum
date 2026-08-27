<?php

namespace App\Http\Controllers\Organization;

use App\Actions\Organization\CreateOrganizationRule;
use App\Actions\Organization\UpdateOrganizationRule;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StoreOrganizationRuleRequest;
use App\Http\Requests\Organization\UpdateOrganizationRuleRequest;
use App\Models\OrganizationRule;
use App\Models\OrganizationScheme;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class OrganizationRuleController extends Controller
{
    /**
     * Create a new matching rule for the given scheme.
     *
     * @param  StoreOrganizationRuleRequest  $request  The incoming request with the validated matcher and target attributes.
     * @param  OrganizationScheme  $scheme  The scheme the rule belongs to.
     * @param  CreateOrganizationRule  $action  Creates the rule, after validating it against the scheme.
     * @return RedirectResponse Redirect back to the previous page.
     *
     * @throws AuthorizationException If the current user cannot update $scheme.
     * @throws ModelNotFoundException If the requested target level does not belong to $scheme.
     * @throws ValidationException If a rule with the same matcher already exists in $scheme.
     */
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

    /**
     * Update an existing matching rule's matcher and target placement.
     *
     * @param  UpdateOrganizationRuleRequest  $request  The incoming request with the validated matcher and target attributes.
     * @param  OrganizationScheme  $scheme  The scheme the rule is expected to belong to.
     * @param  OrganizationRule  $rule  The rule being updated.
     * @param  UpdateOrganizationRule  $action  Applies the update, after validating it against the scheme.
     * @return RedirectResponse Redirect back to the previous page.
     *
     * @throws AuthorizationException If the current user cannot update $scheme.
     * @throws NotFoundHttpException If $rule does not belong to $scheme.
     * @throws ModelNotFoundException If the requested target level does not belong to $scheme.
     * @throws ValidationException If another rule with the same matcher already exists in $scheme.
     */
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

    /**
     * Delete a matching rule.
     *
     * @param  OrganizationScheme  $scheme  The scheme the rule is expected to belong to.
     * @param  OrganizationRule  $rule  The rule to delete.
     * @return RedirectResponse Redirect back to the previous page.
     *
     * @throws AuthorizationException If the current user cannot update $scheme.
     * @throws NotFoundHttpException If $rule does not belong to $scheme.
     */
    public function destroy(OrganizationScheme $scheme, OrganizationRule $rule): RedirectResponse
    {
        $this->authorize('update', $scheme);

        abort_unless($rule->scheme_id === $scheme->id, 404);

        $rule->delete();

        return back();
    }
}
