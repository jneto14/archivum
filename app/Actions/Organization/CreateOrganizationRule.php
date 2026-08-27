<?php

namespace App\Actions\Organization;

use App\Models\OrganizationLevel;
use App\Models\OrganizationRule;
use App\Models\OrganizationScheme;
use Illuminate\Validation\ValidationException;

class CreateOrganizationRule
{
    /**
     * Create a new OrganizationRule mapping a matcher to a target level and preferred value.
     *
     * @param  OrganizationScheme  $scheme  The scheme the rule belongs to.
     * @param  string  $matcherKey  The document attribute this rule matches against (e.g. "document_type").
     * @param  string  $matcherValue  The value of $matcherKey that triggers this rule.
     * @param  OrganizationLevel  $targetLevel  The level a matched document should be placed under.
     * @param  string  $preferredValue  The node value to use/create at $targetLevel when the rule matches.
     * @return OrganizationRule The newly created rule.
     *
     * @throws ValidationException If $targetLevel does not belong to $scheme, or a rule for this matcher already exists in the scheme.
     */
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

    /**
     * @throws ValidationException If $targetLevel does not belong to $scheme.
     */
    private function assertTargetLevelBelongsToScheme(OrganizationScheme $scheme, OrganizationLevel $targetLevel): void
    {
        if ($targetLevel->scheme_id !== $scheme->id) {
            throw ValidationException::withMessages([
                'target_level_id' => __('organization.invalid_rule_target_level'),
            ]);
        }
    }

    /**
     * @throws ValidationException If a rule with this matcher key/value already exists in $scheme.
     */
    private function assertMatcherIsUnique(OrganizationScheme $scheme, string $matcherKey, string $matcherValue): void
    {
        $exists = OrganizationRule::query()
            ->where('scheme_id', $scheme->id)
            ->where('matcher_key', $matcherKey)
            ->where('matcher_value', $matcherValue)
            ->exists();

        if ($exists) {
            throw ValidationException::withMessages([
                'matcher_value' => __('organization.duplicate_rule_matcher'),
            ]);
        }
    }
}
