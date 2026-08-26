<?php

namespace App\Http\Controllers\Organization;

use App\Actions\Organization\CreateScheme;
use App\Actions\Organization\UpdateScheme;
use App\Enums\NodeValueStrategy;
use App\Http\Controllers\Controller;
use App\Http\Requests\Organization\StoreOrganizationSchemeRequest;
use App\Http\Requests\Organization\UpdateOrganizationSchemeRequest;
use App\Models\OrganizationScheme;
use App\Models\Workspace;
use Illuminate\Http\RedirectResponse;

class OrganizationSchemeController extends Controller
{
    public function store(StoreOrganizationSchemeRequest $request, Workspace $workspace, CreateScheme $action): RedirectResponse
    {
        $this->authorize('create', [OrganizationScheme::class, $workspace]);

        $action->handle($workspace, $request->validated('name'), $this->levelsFromRequest($request));

        return back();
    }

    public function update(UpdateOrganizationSchemeRequest $request, OrganizationScheme $scheme, UpdateScheme $action): RedirectResponse
    {
        $this->authorize('update', $scheme);

        $action->handle($scheme, $request->validated('name'));

        return back();
    }

    /**
     * @return array<int, array{name: string, key: string, capacity: int|null, value_strategy: NodeValueStrategy, display_settings: array<string, mixed>|null, metadata: array<string, mixed>|null}>
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
