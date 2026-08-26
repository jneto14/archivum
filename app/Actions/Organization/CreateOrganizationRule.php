<?php

namespace App\Actions\Organization;

use App\Models\OrganizationLevel;
use App\Models\OrganizationRule;
use App\Models\OrganizationScheme;
use Illuminate\Validation\ValidationException;

class CreateOrganizationRule
{
    public function handle(OrganizationScheme $scheme, string $matcherKey, string $matcherValue, OrganizationLevel $targetLevel, string $preferredValue): OrganizationRule
    {
        $this->assertTargetLevelBelongsToScheme($scheme, $targetLevel);
        $this->assertMatcherIsUnique($scheme, $matcherKey, $matcherValue);

        return OrganizationRule::query()->create([
            'scheme_id' => $scheme->id,
            'matcher_key' => $matcherKey,
            'matcher_value' => $matcherValue,
            'target_level_id' => $targetLevel->id,
            'preferred_value' => $preferredValue,
        ]);
    }

    private function assertTargetLevelBelongsToScheme(OrganizationScheme $scheme, OrganizationLevel $targetLevel): void
    {
        if ($targetLevel->scheme_id !== $scheme->id) {
            throw ValidationException::withMessages([
                'target_level_id' => 'The target level must belong to the same scheme.',
            ]);
        }
    }

    private function assertMatcherIsUnique(OrganizationScheme $scheme, string $matcherKey, string $matcherValue): void
    {
        $exists = OrganizationRule::query()
            ->where('scheme_id', $scheme->id)
            ->where('matcher_key', $matcherKey)
            ->where('matcher_value', $matcherValue)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'matcher_value' => 'A rule already exists for this matcher within the scheme.',
            ]);
        }
    }
}
