<?php

namespace App\Models;

use App\Enums\NodeValueStrategy;
use Database\Factories\OrganizationLevelFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use LogicException;

/**
 * @property string $id
 * @property string $scheme_id
 * @property string $name
 * @property string $key
 * @property int $position
 * @property int|null $capacity
 * @property NodeValueStrategy $value_strategy
 * @property array<string, mixed>|null $display_settings
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['scheme_id', 'name', 'key', 'position', 'capacity', 'value_strategy', 'display_settings', 'metadata'])]
class OrganizationLevel extends Model
{
    /** @use HasFactory<OrganizationLevelFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'value_strategy' => NodeValueStrategy::class,
            'display_settings' => 'array',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<OrganizationScheme, $this>
     */
    public function scheme(): BelongsTo
    {
        return $this->belongsTo(OrganizationScheme::class);
    }

    /**
     * @return HasMany<OrganizationNode, $this>
     */
    public function nodes(): HasMany
    {
        return $this->hasMany(OrganizationNode::class, 'level_id');
    }

    /**
     * @return HasMany<OrganizationRule, $this>
     */
    public function rules(): HasMany
    {
        return $this->hasMany(OrganizationRule::class, 'target_level_id');
    }

    /**
     * Find the level immediately below this one in the same scheme, if any.
     */
    public function childLevel(): ?self
    {
        return static::query()
            ->where('scheme_id', $this->scheme_id)
            ->where('position', $this->position + 1)
            ->first();
    }

    /**
     * Determine whether this is the bottommost level of its scheme.
     */
    public function isLeaf(): bool
    {
        return $this->childLevel() === null;
    }

    /**
     * Count the nodes of this level that share the given parent node.
     */
    public function siblingCountUnder(?OrganizationNode $parent): int
    {
        return OrganizationNode::query()
            ->where('level_id', $this->id)
            ->when($parent === null, fn ($query) => $query->whereNull('parent_id'))
            ->when($parent !== null, fn ($query) => $query->where('parent_id', $parent->id))
            ->count();
    }

    /**
     * Determine whether the configured capacity for this level has been reached under the given parent.
     */
    public function capacityReached(?OrganizationNode $parent): bool
    {
        if ($this->capacity === null) {
            return false;
        }

        return $this->siblingCountUnder($parent) >= $this->capacity;
    }

    /**
     * Generate the next node value under the given parent, based on this level's value strategy.
     *
     * @throws LogicException if the value strategy is Manual, which cannot be auto-generated.
     */
    public function nextValueForParent(?OrganizationNode $parent): string
    {
        $position = $this->siblingCountUnder($parent) + 1;

        return match ($this->value_strategy) {
            NodeValueStrategy::Sequential => str_pad((string) $position, 3, '0', STR_PAD_LEFT),
            NodeValueStrategy::Alphabetical => $this->numberToLetters($position),
            NodeValueStrategy::Manual => throw new LogicException('Cannot auto-generate a value for a Manual value-strategy level.'),
        };
    }

    /**
     * Convert a 1-based position into spreadsheet-style letters (1 => A, 26 => Z, 27 => AA, ...).
     */
    private function numberToLetters(int $number): string
    {
        $letters = '';

        while ($number > 0) {
            $number--;
            $letters = chr(65 + ($number % 26)).$letters;
            $number = intdiv($number, 26);
        }

        return $letters;
    }
}
