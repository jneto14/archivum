<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Organization\CreateOrganizationRule;
use App\Actions\Organization\UpdateOrganizationRule;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StoreOrganizationRuleRequest;
use App\Http\Requests\Organization\UpdateOrganizationRuleRequest;
use App\Http\Resources\Api\V1\OrganizationRuleResource;
use App\Models\OrganizationRule;
use App\Models\OrganizationScheme;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The rules that decide where a document is filed when nobody names a node.
 */
class OrganizationRuleController extends Controller
{
    /**
     * Add a matching rule to a scheme.
     *
     * @param StoreOrganizationRuleRequest $request The incoming request with the validated matcher and target.
     * @param OrganizationScheme $scheme The scheme the rule belongs to.
     * @param CreateOrganizationRule $action Creates the rule.
     *
     * @return JsonResponse The created rule, with status 201.
     *
     * @throws AuthorizationException If the token's user cannot update $scheme.
     * @throws ModelNotFoundException If the target level does not belong to $scheme.
     */
    public function store(StoreOrganizationRuleRequest $request, OrganizationScheme $scheme, CreateOrganizationRule $action): JsonResponse
    {
        $this->authorize('update', $scheme);

        $rule = $action->handle(
            $scheme,
            $request->validated('matcher_key'),
            $request->validated('matcher_value'),
            $scheme->levels()->where('id', $request->validated('target_level_id'))->firstOrFail(),
            $request->validated('preferred_value'),
        );

        return (new OrganizationRuleResource($rule->load('targetLevel')))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Change a rule's matcher or where it files to.
     *
     * @param UpdateOrganizationRuleRequest $request The incoming request with the validated matcher and target.
     * @param OrganizationScheme $scheme The scheme the rule is expected to belong to.
     * @param OrganizationRule $rule The rule being updated.
     * @param UpdateOrganizationRule $action Applies the change.
     *
     * @return OrganizationRuleResource The updated rule.
     *
     * @throws AuthorizationException If the token's user cannot update $scheme.
     * @throws NotFoundHttpException If $rule does not belong to $scheme.
     * @throws ModelNotFoundException If the target level does not belong to $scheme.
     */
    public function update(
        UpdateOrganizationRuleRequest $request,
        OrganizationScheme $scheme,
        OrganizationRule $rule,
        UpdateOrganizationRule $action,
    ): OrganizationRuleResource {
        $this->authorize('update', $scheme);

        abort_unless($rule->scheme_id === $scheme->id, 404);

        $action->handle(
            $rule,
            $request->validated('matcher_key'),
            $request->validated('matcher_value'),
            $scheme->levels()->where('id', $request->validated('target_level_id'))->firstOrFail(),
            $request->validated('preferred_value'),
        );

        return new OrganizationRuleResource($rule->refresh()->load('targetLevel'));
    }

    /**
     * Delete a matching rule.
     *
     * @param OrganizationScheme $scheme The scheme the rule is expected to belong to.
     * @param OrganizationRule $rule The rule to delete.
     *
     * @return JsonResponse An empty 204.
     *
     * @throws AuthorizationException If the token's user cannot update $scheme.
     * @throws NotFoundHttpException If $rule does not belong to $scheme.
     */
    public function destroy(OrganizationScheme $scheme, OrganizationRule $rule): JsonResponse
    {
        $this->authorize('update', $scheme);

        abort_unless($rule->scheme_id === $scheme->id, 404);

        $rule->delete();

        return new JsonResponse(status: 204);
    }
}
