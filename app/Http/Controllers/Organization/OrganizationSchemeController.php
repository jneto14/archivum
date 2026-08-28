<?php

declare(strict_types=1);

namespace App\Http\Controllers\Organization;

use App\Actions\Organization\CreateScheme;
use App\Actions\Organization\UpdateScheme;
use App\Enums\NodeValueStrategy;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StoreOrganizationSchemeRequest;
use App\Http\Requests\Organization\UpdateOrganizationSchemeRequest;
use App\Models\OrganizationLevel;
use App\Models\OrganizationRule;
use App\Models\OrganizationScheme;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class OrganizationSchemeController extends Controller
{
    /**
     * Show the form for creating a new organization scheme.
     *
     * @param Workspace $workspace The workspace the new scheme will belong to.
     *
     * @return Response The rendered organization scheme form page.
     *
     * @throws AuthorizationException If the current user cannot create schemes in $workspace.
     */
    public function create(Workspace $workspace): Response
    {
        $this->authorize('create', [OrganizationScheme::class, $workspace]);

        return Inertia::render('organization/form', [
            'workspaceId' => $workspace->id,
            'scheme' => null,
        ]);
    }

    /**
     * Show a scheme's levels and matching rules, together with a preview of
     * the resulting path they produce.
     *
     * @param Request $request The incoming request, used to resolve the acting user.
     * @param OrganizationScheme $scheme The scheme being viewed.
     *
     * @return Response The rendered organization scheme show page.
     *
     * @throws AuthorizationException If the current user cannot view $scheme.
     */
    public function show(Request $request, OrganizationScheme $scheme): Response
    {
        $this->authorize('view', $scheme);

        $scheme->load(['levels' => fn ($query) => $query->orderBy('position'), 'rules.targetLevel']);

        return Inertia::render('organization/show', [
            'scheme' => [
                'id' => $scheme->id,
                'name' => $scheme->name,
                'levels' => $scheme->levels->map(fn (OrganizationLevel $level) => [
                    'id' => $level->id,
                    'name' => $level->name,
                    'key' => $level->key,
                    'position' => $level->position,
                    'capacity' => $level->capacity,
                    'value_strategy' => $level->value_strategy->value,
                    'is_leaf' => $level->isLeaf(),
                ])->values()->all(),
                'rules' => $scheme->rules->map(fn (OrganizationRule $rule) => [
                    'id' => $rule->id,
                    'matcher_key' => $rule->matcher_key,
                    'matcher_value' => $rule->matcher_value,
                    'target_level' => ['id' => $rule->targetLevel->id, 'name' => $rule->targetLevel->name],
                    'preferred_value' => $rule->preferred_value,
                ])->values()->all(),
            ],
            'canManage' => $scheme->workspace->isAdmin($request->user()),
            'resultingPath' => $this->resultingPath($scheme),
        ]);
    }

    /**
     * Show the form for renaming an existing organization scheme.
     *
     * @param OrganizationScheme $scheme The scheme being edited.
     *
     * @return Response The rendered organization scheme form page.
     *
     * @throws AuthorizationException If the current user cannot update $scheme.
     */
    public function edit(OrganizationScheme $scheme): Response
    {
        $this->authorize('update', $scheme);

        return Inertia::render('organization/form', [
            'workspaceId' => $scheme->workspace_id,
            'scheme' => ['id' => $scheme->id, 'name' => $scheme->name],
        ]);
    }

    /**
     * Create a new organization scheme together with its ordered levels.
     *
     * @param StoreOrganizationSchemeRequest $request The incoming request with the validated name and levels.
     * @param Workspace $workspace The workspace the scheme is created in.
     * @param CreateScheme $action Creates the scheme and its levels.
     *
     * @return RedirectResponse Redirect to the newly created scheme's show page.
     *
     * @throws AuthorizationException If the current user cannot create schemes in $workspace.
     * @throws ValidationException If $workspace already has a scheme, no levels are given, or level keys are duplicated.
     */
    public function store(StoreOrganizationSchemeRequest $request, Workspace $workspace, CreateScheme $action): RedirectResponse
    {
        $this->authorize('create', [OrganizationScheme::class, $workspace]);

        $scheme = $action->handle($workspace, $request->validated('name'), $this->levelsFromRequest($request));

        Inertia::flash('toast', ['type' => 'success', 'message' => __('organization.scheme_created')]);

        return redirect()->route('organization.schemes.show', $scheme);
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

        Inertia::flash('toast', ['type' => 'success', 'message' => __('organization.scheme_updated')]);

        return back();
    }

    /**
     * Preview, for each level, the value a matching rule would assign it, so
     * an admin can see the effect of the scheme's rules without filing a
     * real document.
     *
     * @param OrganizationScheme $scheme The scheme whose levels and rules are previewed.
     *
     * @return array{levels: array<int, array{level_id: string, level_name: string, sample: string|null}>, path: string} Per-level samples and the joined preview path.
     */
    private function resultingPath(OrganizationScheme $scheme): array
    {
        $samples = $scheme->levels->map(function (OrganizationLevel $level) use ($scheme) {
            $rule = $scheme->rules->first(fn (OrganizationRule $rule) => $rule->target_level_id === $level->id);

            return [
                'level_id' => $level->id,
                'level_name' => $level->name,
                'sample' => $rule?->preferred_value,
            ];
        })->values()->all();

        return [
            'levels' => $samples,
            'path' => implode('-', array_map(fn (array $sample) => $sample['sample'] ?? '···', $samples)),
        ];
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
