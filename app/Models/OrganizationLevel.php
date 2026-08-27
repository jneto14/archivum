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
     * Find the level immediately below this one (position + 1) within the same scheme.
     *
     * @return self|null The child level, or null if this is the scheme's bottommost level.
     */
    public function childLevel(): ?self
    {
        return static::query()
            ->where('scheme_id', $this->scheme_id)
            ->where('position', $this->position + 1)
            ->first();
    }

    /**
     * Determine whether this is the bottommost level of its scheme, i.e. it has no child level.
     *
     * @return bool True if no level in this scheme sits immediately below this one.
     */
    public function isLeaf(): bool
    {
        return $this->childLevel() === null;
    }

    /**
     * Count the existing nodes of this level that share the given parent node.
     *
     * @param  OrganizationNode|null  $parent  The parent node to count direct children under, or null to count root-level nodes of this level (those with no parent).
     * @return int The number of sibling nodes found.
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
     * Determine whether this level's configured capacity has been reached among the
     * existing nodes under the given parent. A level without a configured capacity is
     * never considered full.
     *
     * @param  OrganizationNode|null  $parent  The parent node to check sibling capacity under, or null for root-level nodes of this level.
     * @return bool True if a capacity is configured and the sibling count under $parent has reached it.
     */
    public function capacityReached(?OrganizationNode $parent): bool
    {
        if ($this->capacity === null) {
            return false;
        }

        return $this->siblingCountUnder($parent) >= $this->capacity;
    }

    /**
     * Generate the next node value under the given parent, based on this level's value strategy
     * (Sequential: zero-padded incrementing number; Alphabetical: spreadsheet-style letters).
     *
     * @param  OrganizationNode|null  $parent  The parent node to count existing siblings under, or null for a root-level node.
     * @return string The generated value, ready to assign to a new OrganizationNode.
     *
     * @throws LogicException If this level's value_strategy is Manual, since Manual values must be supplied explicitly and cannot be auto-generated.
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
     *
     * @param  int  $number  The 1-based position to convert.
     * @return string The resulting letter sequence.
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
