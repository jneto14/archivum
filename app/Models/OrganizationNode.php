<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\OrganizationNodeFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $level_id
 * @property string|null $parent_id
 * @property string $value
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['level_id', 'parent_id', 'value'])]
class OrganizationNode extends Model
{
    /** @use HasFactory<OrganizationNodeFactory> */
    use HasFactory, HasUuids;

    /**
     * @return BelongsTo<OrganizationLevel, $this>
     */
    public function level(): BelongsTo
    {
        return $this->belongsTo(OrganizationLevel::class);
    }

    /**
     * @return BelongsTo<OrganizationNode, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<OrganizationNode, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * Build the full hierarchical path of this node's value joined with its ancestors'
     * values, walking up the parent chain to the root.
     *
     * @param string $separator The string used to join each ancestor's value, root first.
     *
     * @return string The joined path, e.g. "A-01-003" for a three-level hierarchy with the default separator.
     */
    public function path(string $separator = '-'): string
    {
        $segments = [$this->value];
        $node = $this;

        while ($node->parent !== null) {
            $node = $node->parent;
            array_unshift($segments, $node->value);
        }

        return implode($separator, $segments);
    }
}
