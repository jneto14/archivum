<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\OrganizationRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $scheme_id
 * @property string $matcher_key
 * @property string $matcher_value
 * @property string $target_level_id
 * @property string $preferred_value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['scheme_id', 'matcher_key', 'matcher_value', 'target_level_id', 'preferred_value'])]
class OrganizationRule extends Model
{
    /** @use HasFactory<OrganizationRuleFactory> */
    use HasFactory, HasUuids;

    /**
     * @return BelongsTo<OrganizationScheme, $this>
     */
    public function scheme(): BelongsTo
    {
        return $this->belongsTo(OrganizationScheme::class);
    }

    /**
     * @return BelongsTo<OrganizationLevel, $this>
     */
    public function targetLevel(): BelongsTo
    {
        return $this->belongsTo(OrganizationLevel::class, 'target_level_id');
    }
}
