<?php

declare(strict_types=1);

namespace App\Actions\Organization;

use App\Models\OrganizationLevel;
use App\Models\OrganizationRule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

class UpdateOrganizationRule
{
    /**
     * Update an OrganizationRule's matcher and target placement.
     *
     * @param OrganizationRule $rule The rule to update.
     * @param string $matcherKey The document attribute this rule matches against (e.g. "document_type").
     * @param string $matcherValue The value of $matcherKey that triggers this rule.
     * @param OrganizationLevel $targetLevel The level a matched document should be placed under.
     * @param string $preferredValue The node value to use/create at $targetLevel when the rule matches.
     *
     * @return OrganizationRule The updated rule.
     *
     * @throws ValidationException If $targetLevel does not belong to $rule's scheme, or another rule for this matcher already exists in the scheme.
     */
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

    /**
     * @param OrganizationRule $rule The rule being updated.
     * @param OrganizationLevel $targetLevel The candidate target level.
     *
     * @return void No return value when valid.
     *
     * @throws ValidationException If $targetLevel does not belong to $rule's scheme.
     */
    private function assertTargetLevelBelongsToScheme(OrganizationRule $rule, OrganizationLevel $targetLevel): void
    {
        if ($targetLevel->scheme_id !== $rule->scheme_id) {
            // Flashed as well as thrown: the message is addressed to a
            // field — 'target_level_id' — that no page renders, so on its own it
            // arrives and is dropped. The toast is what is actually seen.
            Inertia::flash('toast', ['type' => 'error', 'message' => __('organization.invalid_rule_target_level')]);

            throw ValidationException::withMessages([
                'target_level_id' => __('organization.invalid_rule_target_level'),
            ]);
        }
    }

    /**
     * @param OrganizationRule $rule The rule being updated (excluded from its own uniqueness check).
     * @param string $matcherKey The candidate matcher key.
     * @param string $matcherValue The candidate matcher value.
     *
     * @return void No return value when unique.
     *
     * @throws ValidationException If a different rule in $rule's scheme already has this matcher key/value.
     */
    private function assertMatcherIsUnique(OrganizationRule $rule, string $matcherKey, string $matcherValue): void
    {
        $exists = OrganizationRule::query()
            ->where('scheme_id', $rule->scheme_id)
            ->where('matcher_key', $matcherKey)
            ->where('matcher_value', $matcherValue)
            ->whereKeyNot($rule->id)
            ->exists();

        if ($exists) {
            // Flashed as well as thrown: the message is addressed to a
            // field — 'matcher_value' — that no page renders, so on its own it
            // arrives and is dropped. The toast is what is actually seen.
            Inertia::flash('toast', ['type' => 'error', 'message' => __('organization.duplicate_rule_matcher')]);

            throw ValidationException::withMessages([
                'matcher_value' => __('organization.duplicate_rule_matcher'),
            ]);
        }
    }
}
