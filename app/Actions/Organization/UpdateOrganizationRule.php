<?php

namespace App\Actions\Organization;

use App\Models\OrganizationLevel;
use App\Models\OrganizationRule;
use Illuminate\Validation\ValidationException;

class UpdateOrganizationRule
{
    public function handle(OrganizationRule $rule, string $matcherKey, string $matcherValue, OrganizationLevel $targetLevel, string $preferredValue): OrganizationRule
    {
        $this->assertTargetLevelBelongsToScheme($rule, $targetLevel);
        $this->assertMatcherIsUnique($rule, $matcherKey, $matcherValue);

        $rule->update([
            'matcher_key' => $matcherKey,
            'matcher_value' => $matcherValue,
            'target_level_id' => $targetLevel->id,
            'preferred_value' => $preferredValue,
        ]);

        return $rule;
    }

    private function assertTargetLevelBelongsToScheme(OrganizationRule $rule, OrganizationLevel $targetLevel): void
    {
        if ($targetLevel->scheme_id !== $rule->scheme_id) {
            throw ValidationException::withMessages([
                'target_level_id' => __('organization.invalid_rule_target_level'),
            ]);
        }
    }

    private function assertMatcherIsUnique(OrganizationRule $rule, string $matcherKey, string $matcherValue): void
    {
        $exists = OrganizationRule::query()
            ->where('scheme_id', $rule->scheme_id)
            ->where('matcher_key', $matcherKey)
            ->where('matcher_value', $matcherValue)
            ->whereKeyNot($rule->id)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'matcher_value' => __('organization.duplicate_rule_matcher'),
            ]);
        }
    }
}
