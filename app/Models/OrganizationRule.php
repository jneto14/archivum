<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\LogsWorkspaceActivity;
use Database\Factories\OrganizationRuleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Support\LogOptions;

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
    use HasFactory, HasUuids, LogsWorkspaceActivity;

    /**
     * @return LogOptions Logs matcher/target changes under the 'organization_rule' log name.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('organization_rule')
            ->logOnly(['matcher_key', 'matcher_value', 'target_level_id', 'preferred_value'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * @return string|null This rule's scheme's workspace id.
     */
    protected function resolveActivityWorkspaceId(): ?string
    {
        return $this->scheme?->workspace_id;
    }

    /**
     * @return string|null A short label identifying this rule by what it matches.
     */
    protected function resolveActivityLabel(): ?string
    {
        return "{$this->matcher_key} = {$this->matcher_value}";
    }

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
