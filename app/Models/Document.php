<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\LogsWorkspaceActivity;
use App\Enums\CaptureSessionStatus;
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
use Laravel\Scout\Attributes\SearchUsingFullText;
use Laravel\Scout\Searchable;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property string $id
 * @property string $workspace_id
 * @property string $document_type_id
 * @property string $created_by
 * @property string $title
 * @property Carbon|null $document_date
 * @property array<string, mixed>|null $metadata
 * @property string|null $ocr_text
 * @property array<int, array{kind: string, value: string}>|null $metadata_suggestions
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['workspace_id', 'document_type_id', 'created_by', 'title', 'document_date', 'metadata'])]
class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory, HasUuids, LogsWorkspaceActivity, Searchable;

    /**
     * @return LogOptions Logs title/type/date changes under the 'document' log name.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('document')
            ->logOnly(['title', 'document_type_id', 'document_date'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    /**
     * @return string|null This document's workspace id.
     */
    protected function resolveActivityWorkspaceId(): ?string
    {
        return $this->workspace_id;
    }

    /**
     * @return string|null This document's title.
     */
    protected function resolveActivityLabel(): ?string
    {
        return $this->title;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'document_date' => 'date',
            'metadata' => 'array',
            'metadata_suggestions' => 'array',
        ];
    }

    /**
     * Deliberately minimal — the title, plus the text extracted from the
     * document's attachments. `metadata` and relation fields (document type,
     * tags) are structured filters, not Scout text search.
     *
     * `ocr_text` is matched through the full-text index rather than the default
     * `LIKE '%…%'`, which cannot use an index and would scan every row's worth
     * of extracted pages. Note that InnoDB's full-text tokenizer ignores words
     * shorter than `innodb_ft_min_token_size` (3 by default), so two-letter
     * terms will not match inside attachment text.
     *
     * @return array<string, mixed>
     */
    #[SearchUsingFullText(['ocr_text'])]
    public function toSearchableArray(): array
    {
        return [
            'title' => $this->title,
            'ocr_text' => $this->ocr_text,
        ];
    }

    /**
     * Rebuild this document's searchable text from its attachments and
     * re-index it.
     *
     * `documents.ocr_text` is a mirror: the text of each attachment lives on
     * the attachment, and this concatenates all of them. Call it after any
     * change to the set of attachments or to their extracted text — including
     * deletions, or the text of a removed scan stays findable.
     *
     * @return void No return value; updates the column and the search index as a side effect.
     */
    public function refreshOcrText(): void
    {
        $text = $this->attachments()
            ->whereNotNull('ocr_text')
            ->orderBy('created_at')
            ->pluck('ocr_text')
            ->filter(fn (?string $value): bool => filled($value))
            ->implode("\n\n");

        $this->forceFill(['ocr_text' => $text === '' ? null : $text])->save();

        $this->searchable();
    }

    /**
     * Record what this document's extracted text was found to contain, so the
     * review queue can find it without re-reading every document.
     *
     * An empty list is stored as null — "nothing to review" and "not looked at
     * yet" are the same thing to every reader of this column, and null is what
     * the queue filters on.
     *
     * @param array<int, array{kind: string, value: string}> $findings What the heuristics read out of the text.
     *
     * @return void No return value; saves the model as a side effect.
     */
    public function recordMetadataSuggestions(array $findings): void
    {
        $this->forceFill(['metadata_suggestions' => $findings === [] ? null : $findings])->save();
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

    /**
     * @return HasMany<DocumentCaptureSession, $this>
     */
    public function captureSessions(): HasMany
    {
        return $this->hasMany(DocumentCaptureSession::class);
    }

    /**
     * The mobile pairing session currently open for this document, if any.
     * At most one is ever `Active` at a time — starting a new one supersedes
     * whichever was still open (see `CreateCaptureSession`).
     *
     * @return HasOne<DocumentCaptureSession, $this>
     */
    public function activeCaptureSession(): HasOne
    {
        return $this->hasOne(DocumentCaptureSession::class)
            ->where('status', CaptureSessionStatus::Active)
            ->latestOfMany();
    }
}
