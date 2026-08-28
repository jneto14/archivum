<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organization;

use App\Actions\Organization\AddOrganizationLevel;
use App\Actions\Organization\DeleteOrganizationLevel;
use App\Enums\NodeValueStrategy;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StoreOrganizationLevelRequest;
use App\Models\OrganizationLevel;
use App\Models\OrganizationScheme;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class OrganizationLevelController extends Controller
{
    /**
     * Append a new level to the end of the given scheme.
     *
     * @param StoreOrganizationLevelRequest $request The incoming request with the validated level attributes.
     * @param OrganizationScheme $scheme The scheme the new level is appended to.
     * @param AddOrganizationLevel $action Appends the level, after validating its key and Alphabetical capacity.
     *
     * @return RedirectResponse Redirect back to the previous page.
     *
     * @throws AuthorizationException If the current user cannot update $scheme.
     * @throws ValidationException If the level's key is already used within $scheme, or an Alphabetical-strategy level's capacity exceeds 26.
     */
    public function store(StoreOrganizationLevelRequest $request, OrganizationScheme $scheme, AddOrganizationLevel $action): RedirectResponse
    {
        $this->authorize('update', $scheme);

        $action->handle($scheme, [
            'name' => (string) $request->validated('name'),
            'key' => (string) $request->validated('key'),
            'capacity' => $request->validated('capacity') !== null ? (int) $request->validated('capacity') : null,
            'value_strategy' => NodeValueStrategy::from((string) $request->validated('value_strategy')),
            'display_settings' => $request->validated('display_settings'),
            'metadata' => $request->validated('metadata'),
        ]);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('organization.level_created')]);

        return back();
    }

    /**
     * Delete a single level from the given scheme.
     *
     * @param OrganizationScheme $scheme The scheme the level is expected to belong to.
     * @param OrganizationLevel $level The level to delete.
     * @param DeleteOrganizationLevel $action Deletes the level, after validating it is the last one and has no nodes.
     *
     * @return RedirectResponse Redirect back to the previous page.
     *
     * @throws AuthorizationException If the current user cannot update $scheme.
     * @throws NotFoundHttpException If $level does not belong to $scheme.
     * @throws ValidationException If $level is not the last level in $scheme, or has nodes.
     */
    public function destroy(OrganizationScheme $scheme, OrganizationLevel $level, DeleteOrganizationLevel $action): RedirectResponse
    {
        $this->authorize('update', $scheme);

        abort_unless($level->scheme_id === $scheme->id, 404);

        $action->handle($level);

        Inertia::flash('toast', ['type' => 'success', 'message' => __('organization.level_deleted')]);

        return back();
    }
}
