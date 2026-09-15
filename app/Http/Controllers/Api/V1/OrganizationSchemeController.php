<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Actions\Organization\CreateScheme;
use App\Actions\Organization\UpdateScheme;
use App\Enums\NodeValueStrategy;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StoreOrganizationSchemeRequest;
use App\Http\Requests\Organization\UpdateOrganizationSchemeRequest;
use App\Http\Resources\Api\V1\OrganizationSchemeResource;
use App\Models\OrganizationScheme;
use App\Models\Workspace;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class OrganizationSchemeController extends Controller
{
    /**
     * List a workspace's organization schemes.
     *
     * @param Workspace $workspace The workspace whose schemes are listed.
     *
     * @return AnonymousResourceCollection The workspace's schemes, oldest first.
     *
     * @throws AuthorizationException If the token's user cannot create schemes in $workspace.
     */
    public function index(Workspace $workspace): AnonymousResourceCollection
    {
        $this->authorize('create', [OrganizationScheme::class, $workspace]);

        return OrganizationSchemeResource::collection(
            OrganizationScheme::query()
                ->where('workspace_id', $workspace->id)
                ->oldest()
                ->get(),
        );
    }

    /**
     * Read a scheme, with its levels in order and the rules that file into them.
     *
     * The shape a client needs before it can move a document automatically:
     * the rules say which level a document lands on, and the levels say
     * whether a value there is allocated or typed.
     *
     * @param OrganizationScheme $scheme The scheme being read.
     *
     * @return OrganizationSchemeResource The scheme, its levels and its rules.
     *
     * @throws AuthorizationException If the token's user cannot view $scheme.
     */
    public function show(OrganizationScheme $scheme): OrganizationSchemeResource
    {
        $this->authorize('view', $scheme);

        $scheme->load([
            'levels' => fn (Relation $levels) => $levels->getQuery()->orderBy('position'),
            'rules.targetLevel',
        ]);

        return new OrganizationSchemeResource($scheme);
    }

    /**
     * Create a scheme, with the levels it is made of.
     *
     * The levels come with it rather than being added afterwards: a scheme
     * with no levels can hold nothing, and the order they arrive in is the
     * order of the archive.
     *
     * @param StoreOrganizationSchemeRequest $request The incoming request with the validated name and levels.
     * @param Workspace $workspace The workspace the scheme belongs to.
     * @param CreateScheme $action Creates the scheme and its levels.
     *
     * @return JsonResponse The created scheme, with status 201.
     *
     * @throws AuthorizationException If the token's user cannot create schemes in $workspace.
     */
    public function store(StoreOrganizationSchemeRequest $request, Workspace $workspace, CreateScheme $action): JsonResponse
    {
        $this->authorize('create', [OrganizationScheme::class, $workspace]);

        $levels = [];

        foreach ($request->validated('levels') as $level) {
            $levels[] = [
                'name' => (string) $level['name'],
                'key' => (string) $level['key'],
                'capacity' => isset($level['capacity']) ? (int) $level['capacity'] : null,
                'has_printable_label' => (bool) ($level['has_printable_label'] ?? false),
                'value_strategy' => NodeValueStrategy::from((string) $level['value_strategy']),
                'display_settings' => $level['display_settings'] ?? null,
                'metadata' => $level['metadata'] ?? null,
            ];
        }

        $scheme = $action->handle($workspace, $request->validated('name'), $levels);

        return (new OrganizationSchemeResource(
            $scheme->load(['levels' => fn (Relation $levels) => $levels->getQuery()->orderBy('position')]),
        ))->response()->setStatusCode(201);
    }

    /**
     * Rename a scheme.
     *
     * @param UpdateOrganizationSchemeRequest $request The incoming request with the validated name.
     * @param OrganizationScheme $scheme The scheme being renamed.
     * @param UpdateScheme $action Applies the rename.
     *
     * @return OrganizationSchemeResource The renamed scheme.
     *
     * @throws AuthorizationException If the token's user cannot update $scheme.
     */
    public function update(UpdateOrganizationSchemeRequest $request, OrganizationScheme $scheme, UpdateScheme $action): OrganizationSchemeResource
    {
        $this->authorize('update', $scheme);

        $action->handle($scheme, $request->validated('name'));

        return new OrganizationSchemeResource($scheme->refresh());
    }
}
