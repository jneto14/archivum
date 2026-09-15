<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Organization\AddOrganizationLevel;
use App\Actions\Organization\DeleteOrganizationLevel;
use App\Actions\Organization\UpdateOrganizationLevel;
use App\Enums\NodeValueStrategy;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StoreOrganizationLevelRequest;
use App\Http\Requests\Organization\UpdateOrganizationLevelRequest;
use App\Http\Resources\Api\V1\OrganizationLevelResource;
use App\Models\OrganizationLevel;
use App\Models\OrganizationScheme;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class OrganizationLevelController extends Controller
{
    /**
     * Add a level to the bottom of a scheme.
     *
     * @param StoreOrganizationLevelRequest $request The incoming request with the validated level attributes.
     * @param OrganizationScheme $scheme The scheme the level is added to.
     * @param AddOrganizationLevel $action Appends the level.
     *
     * @return JsonResponse The created level, with status 201.
     *
     * @throws AuthorizationException If the token's user cannot update $scheme.
     */
    public function store(StoreOrganizationLevelRequest $request, OrganizationScheme $scheme, AddOrganizationLevel $action): JsonResponse
    {
        $this->authorize('update', $scheme);

        $level = $action->handle($scheme, [
            'name' => (string) $request->validated('name'),
            'key' => (string) $request->validated('key'),
            'capacity' => $request->validated('capacity') !== null ? (int) $request->validated('capacity') : null,
            'has_printable_label' => (bool) $request->validated('has_printable_label'),
            'value_strategy' => NodeValueStrategy::from((string) $request->validated('value_strategy')),
            'display_settings' => $request->validated('display_settings'),
            'metadata' => $request->validated('metadata'),
        ]);

        return (new OrganizationLevelResource($level))->response()->setStatusCode(201);
    }

    /**
     * Change whether a level's nodes carry printable labels.
     *
     * @param UpdateOrganizationLevelRequest $request The incoming request with the validated flag.
     * @param OrganizationScheme $scheme The scheme the level is expected to belong to.
     * @param OrganizationLevel $level The level being updated.
     * @param UpdateOrganizationLevel $action Applies the change.
     *
     * @return OrganizationLevelResource The updated level.
     *
     * @throws AuthorizationException If the token's user cannot update $scheme.
     * @throws NotFoundHttpException If $level does not belong to $scheme.
     */
    public function update(
        UpdateOrganizationLevelRequest $request,
        OrganizationScheme $scheme,
        OrganizationLevel $level,
        UpdateOrganizationLevel $action,
    ): OrganizationLevelResource {
        $this->authorize('update', $scheme);

        abort_unless($level->scheme_id === $scheme->id, 404);

        $action->handle($level, (bool) $request->validated('has_printable_label'));

        return new OrganizationLevelResource($level->refresh());
    }

    /**
     * Remove a level from a scheme.
     *
     * @param OrganizationScheme $scheme The scheme the level is expected to belong to.
     * @param OrganizationLevel $level The level to remove.
     * @param DeleteOrganizationLevel $action Deletes the level.
     *
     * @return JsonResponse An empty 204.
     *
     * @throws AuthorizationException If the token's user cannot update $scheme.
     * @throws NotFoundHttpException If $level does not belong to $scheme.
     * @throws ValidationException If the level still holds nodes, or is not the scheme's last.
     */
    public function destroy(OrganizationScheme $scheme, OrganizationLevel $level, DeleteOrganizationLevel $action): JsonResponse
    {
        $this->authorize('update', $scheme);

        abort_unless($level->scheme_id === $scheme->id, 404);

        $action->handle($level);

        return new JsonResponse(status: 204);
    }
}
