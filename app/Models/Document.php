<?php

namespace App\Models;

use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $document_type_id
 * @property string $created_by
 * @property string $title
 * @property Carbon|null $document_date
 * @property array<string, mixed>|null $metadata
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['workspace_id', 'document_type_id', 'created_by', 'title', 'document_date', 'metadata'])]
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'document_date' => 'date',
            'metadata' => 'array',
        ];
    }

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<DocumentType, $this>
     */
    public function documentType(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return BelongsToMany<Tag, $this, DocumentTag>
     */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'document_tags')->using(DocumentTag::class)->withTimestamps();
    }

    /**
     * @return HasMany<DocumentLocation, $this>
     */
    public function locations(): HasMany
    {
        return $this->hasMany(DocumentLocation::class)->orderByDesc('id');
    }

    /**
     * @return HasOne<DocumentLocation, $this>
     */
    public function currentLocation(): HasOne
    {
        return $this->hasOne(DocumentLocation::class)->latestOfMany();
    }

    /**
     * @return HasMany<DocumentAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(DocumentAttachment::class);
    }
}
