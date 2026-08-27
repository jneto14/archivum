<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organization;

use App\Actions\Organization\CreateScheme;
use App\Actions\Organization\UpdateScheme;
use App\Enums\NodeValueStrategy;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StoreOrganizationSchemeRequest;
use App\Http\Requests\Organization\UpdateOrganizationSchemeRequest;
use App\Models\OrganizationScheme;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Validation\ValidationException;

class OrganizationSchemeController extends Controller
{
    /**
     * Create a new organization scheme together with its ordered levels.
     *
     * @param StoreOrganizationSchemeRequest $request The incoming request with the validated name and levels.
     * @param Workspace $workspace The workspace the scheme is created in.
     * @param CreateScheme $action Creates the scheme and its levels.
     *
     * @return RedirectResponse Redirect back to the previous page.
     *
     * @throws AuthorizationException If the current user cannot create schemes in $workspace.
     * @throws ValidationException If no levels are given, or level keys are duplicated.
     */
    public function store(StoreOrganizationSchemeRequest $request, Workspace $workspace, CreateScheme $action): RedirectResponse
    {
        $this->authorize('create', [OrganizationScheme::class, $workspace]);

        $action->handle($workspace, $request->validated('name'), $this->levelsFromRequest($request));

        return back();
    }

    /**
     * Update an organization scheme's attributes.
     *
     * @param UpdateOrganizationSchemeRequest $request The incoming request with the validated name.
     * @param OrganizationScheme $scheme The scheme being updated.
     * @param UpdateScheme $action Applies the update.
     *
     * @return RedirectResponse Redirect back to the previous page.
     *
     * @throws AuthorizationException If the current user cannot update $scheme.
     */
    public function update(UpdateOrganizationSchemeRequest $request, OrganizationScheme $scheme, UpdateScheme $action): RedirectResponse
    {
        $this->authorize('update', $scheme);

        $action->handle($scheme, $request->validated('name'));

        return back();
    }

    /**
     * Normalize the request's raw `levels` payload into the shape expected by CreateScheme.
     *
     * @param StoreOrganizationSchemeRequest $request The incoming request holding the validated `levels` array.
     *
     * @return array<int, array{name: string, key: string, capacity: int|null, value_strategy: NodeValueStrategy, display_settings: array<string, mixed>|null, metadata: array<string, mixed>|null}> The normalized, ordered level definitions.
     */
    private function levelsFromRequest(StoreOrganizationSchemeRequest $request): array
    {
        $levels = [];

        foreach ($request->validated('levels') as $level) {
            $levels[] = [
                'name' => (string) $level['name'],
                'key' => (string) $level['key'],
                'capacity' => isset($level['capacity']) ? (int) $level['capacity'] : null,
                'value_strategy' => NodeValueStrategy::from((string) $level['value_strategy']),
                'display_settings' => $level['display_settings'] ?? null,
                'metadata' => $level['metadata'] ?? null,
            ];
        }

        return $levels;
    }
}
