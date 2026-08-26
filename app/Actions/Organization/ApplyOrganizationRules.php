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
     * @param  array<string, string>  $criteria
     * @return array{level: OrganizationLevel, preferred_value: string}|null
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
