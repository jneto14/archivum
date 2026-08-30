<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\LogsWorkspaceActivity;
use Database\Factories\OrganizationSchemeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $name
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['workspace_id', 'name'])]
class OrganizationScheme extends Model
{
    /** @use HasFactory<OrganizationSchemeFactory> */
    use HasFactory, HasUuids, LogsWorkspaceActivity;

    /**
     * @return LogOptions Logs name changes under the 'organization_scheme' log name.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('organization_scheme')
            ->logOnly(['name'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * @return string|null This scheme's workspace id.
     */
    protected function resolveActivityWorkspaceId(): ?string
    {
        return $this->workspace_id;
    }

    /**
     * @return string|null This scheme's name.
     */
    protected function resolveActivityLabel(): ?string
    {
        return $this->name;
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return HasMany<OrganizationLevel, $this>
     */
    public function levels(): HasMany
    {
        return $this->hasMany(OrganizationLevel::class, 'scheme_id')->orderBy('position');
    }

    /**
     * @return HasMany<OrganizationRule, $this>
     */
    public function rules(): HasMany
    {
        return $this->hasMany(OrganizationRule::class, 'scheme_id');
    }
}
