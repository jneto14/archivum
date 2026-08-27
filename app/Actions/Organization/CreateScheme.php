<?php

namespace App\Actions\Organization;

use App\Enums\NodeValueStrategy;
use App\Models\OrganizationLevel;
use App\Models\OrganizationScheme;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CreateScheme
{
    /**
     * Create a new OrganizationScheme together with its ordered levels.
     *
     * @param  array<int, array{name: string, key: string, capacity?: int|null, value_strategy: NodeValueStrategy, display_settings?: array<string, mixed>|null, metadata?: array<string, mixed>|null}>  $levels
     */
    public function handle(Workspace $workspace, string $name, array $levels): OrganizationScheme
    {
        $this->assertLevelsAreConsistent($levels);

        return DB::transaction(function () use ($workspace, $name, $levels): OrganizationScheme {
            $scheme = OrganizationScheme::query()->create([
                'workspace_id' => $workspace->id,
                'name' => $name,
            ]);

            foreach ($levels as $index => $level) {
                OrganizationLevel::query()->create([
                    'scheme_id' => $scheme->id,
                    'name' => $level['name'],
                    'key' => $level['key'],
                    'position' => $index + 1,
                    'capacity' => $level['capacity'] ?? null,
                    'value_strategy' => $level['value_strategy'],
                    'display_settings' => $level['display_settings'] ?? null,
                    'metadata' => $level['metadata'] ?? null,
                ]);
            }

            return $scheme;
        });
    }

    /**
     * @param  array<int, array{key: string}>  $levels
     */
    private function assertLevelsAreConsistent(array $levels): void
    {
        if ($levels === []) {
            throw ValidationException::withMessages([
                'levels' => __('organization.levels_required'),
            ]);
        }

        $keys = array_column($levels, 'key');

        if (count($keys) !== count(array_unique($keys))) {
            throw ValidationException::withMessages([
                'levels' => __('organization.duplicate_level_keys'),
            ]);
        }
    }
}
