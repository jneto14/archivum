<?php

namespace App\Actions\Organization;

use App\Models\OrganizationLevel;
use App\Models\OrganizationRule;
use App\Models\OrganizationScheme;
use Illuminate\Database\Eloquent\Builder;

class ApplyOrganizationRules
{
    /**
     * Resolve the preferred placement for the given criteria, if any
     * OrganizationRule in the scheme matches.
     *
     * @param  OrganizationScheme  $scheme  The scheme whose rules are evaluated.
     * @param  array<string, string>  $criteria  Matcher key/value pairs (e.g. document attributes) to match against the scheme's rules.
     * @return array{level: OrganizationLevel, preferred_value: string}|null The matched rule's target level and preferred value, or null if no rule matches (or $criteria is empty).
     */
    public function handle(OrganizationScheme $scheme, array $criteria): ?array
    {
        if ($criteria === []) {
            return null;
        }

        $rule = OrganizationRule::query()
            ->where('scheme_id', $scheme->id)
            ->where(function (Builder $query) use ($criteria) {
                foreach ($criteria as $key => $value) {
                    $query->orWhere(function (Builder $query) use ($key, $value) {
                        $query->where('matcher_key', $key)->where('matcher_value', $value);
                    });
                }
            })
            ->with('targetLevel')
            ->first();

        if ($rule === null) {
            return null;
        }

        return [
            'level' => $rule->targetLevel,
            'preferred_value' => $rule->preferred_value,
        ];
    }
}
