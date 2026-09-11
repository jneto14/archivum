<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\LogsWorkspaceActivity;
use App\Enums\OcrReviewOutcome;
use App\Enums\OcrStatus;
use Carbon\CarbonInterface;
use Database\Factories\DocumentAttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Support\LogOptions;

/**
 * @property string $id
 * @property string $document_id
 * @property string $uploaded_by
 * @property string $disk
 * @property string $path
 * @property string $filename
 * @property string $mime_type
 * @property int $size
 * @property string $checksum
 * @property Carbon|null $file_uploaded_at
 * @property OcrStatus $ocr_status
 * @property string|null $ocr_text
 * @property int|null $ocr_word_count
 * @property int|null $ocr_confident_word_count
 * @property Carbon|null $ocr_reviewed_at
 * @property OcrReviewOutcome|null $ocr_review_outcome
 * @property string|null $ocr_error
 * @property Carbon|null $ocr_extracted_at
 * @property int|null $text_simhash
 * @property string|null $duplicate_of_attachment_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 * @property bool $trashed_with_document
 */
#[Fillable(['document_id', 'uploaded_by', 'disk', 'path', 'filename', 'mime_type', 'size', 'checksum', 'file_uploaded_at'])]
class DocumentAttachment extends Model
{
    /** @use HasFactory<DocumentAttachmentFactory> */
    use HasFactory, HasUuids, LogsWorkspaceActivity, SoftDeletes;

    /**
     * The only content types this application will ever render in the browser,
     * and the exact type each is served as.
     *
     * An attachment is a file somebody uploaded, served from the application's
     * own origin, and uploads are deliberately unrestricted. Letting the
     * browser decide what a file is means an uploaded `invoice.html` comes back
     * as `text/html` and its script runs with the viewer's session, so the type
     * is chosen from this list rather than taken from the file (ARC-95).
     *
     * `image/svg+xml` is deliberately absent. An SVG is a document that can
     * carry script, not a picture, and it is the one image type that would turn
     * this list back into the hole it closes.
     *
     * It lives on the model rather than in the controller because the interface
     * needs the same answer: a dialog that decides what it can display from the
     * mime type alone will disagree with the server the moment this list moves,
     * and did — an SVG rendered as a broken image instead of the "cannot
     * preview" message.
     *
     * @var list<string>
     */
    public const INLINE_SAFE_TYPES = [
        'application/pdf',
        'image/jpeg',
        'image/png',
        'image/gif',
        'image/webp',
        'image/avif',
    ];

    /**
     * Attachments are only ever uploaded or removed — they're never edited in
     * place — so there's no 'updated' event worth recording.
     *
     * @var array<int, string>
     */
    protected static $recordEvents = ['created', 'deleted'];

    /**
     * Serialised so the preview dialog asks the server what it may show rather
     * than working it out from the mime type on its own.
     *
     * @var list<string>
     */
    protected $appends = ['is_previewable'];

    /**
     * The OCR columns are deliberately absent from `#[Fillable]` — they are
     * never set from a request, only by `ExtractAttachmentText` through the
     * `markOcr*` methods below.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'ocr_status' => OcrStatus::class,
            'ocr_review_outcome' => OcrReviewOutcome::class,
            'ocr_extracted_at' => 'datetime',
            'ocr_reviewed_at' => 'datetime',
            'file_uploaded_at' => 'datetime',
            'text_simhash' => 'integer',
            'trashed_with_document' => 'boolean',
        ];
    }

    /**
     * Whether this attachment is one the browser may render inline.
     *
     * @return Attribute<bool, never>
     */
    protected function isPreviewable(): Attribute
    {
        return Attribute::get(
            fn (): bool => in_array($this->mime_type, self::INLINE_SAFE_TYPES, true),
        );
    }

    /**
     * @return LogOptions Logs filename under the 'document_attachment' log name.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('document_attachment')
            ->logOnly(['filename'])
            ->dontLogEmptyChanges();
    }

    /**
     * @return string|null This attachment's document's workspace id.
     */
    protected function resolveActivityWorkspaceId(): ?string
    {
        return $this->document?->workspace_id;
    }

    /**
     * @return string|null This attachment's filename.
     */
    protected function resolveActivityLabel(): ?string
    {
        return $this->filename;
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * The files this attachment used to hold, newest replacement first.
     *
     * Only the superseded ones: the file that is current lives on this row.
     * Ordered on the relation rather than at each call site, because "the
     * version history" is always the same list in the same order — the page
     * that shows it, the restore that walks it and the purge that unlinks its
     * files would otherwise each have to remember.
     *
     * @return HasMany<DocumentAttachmentVersion, $this>
     */
    public function versions(): HasMany
    {
        return $this->hasMany(DocumentAttachmentVersion::class)->latest('superseded_at');
    }

    /**
     * When the file this attachment is holding right now was uploaded.
     *
     * `created_at` answers a different question — when the attachment was
     * created — and the two only agree until something replaces the original
     * file. The fallback covers rows written before versions existed and by
     * factories that have no reason to know about the column.
     *
     * @return CarbonInterface|null The current file's upload time, or null on an unsaved model.
     */
    public function fileUploadedAt(): ?CarbonInterface
    {
        return $this->file_uploaded_at ?? $this->created_at;
    }

    /**
     * The earlier attachment this one appears to be another copy of, until
     * somebody says otherwise.
     *
     * @return BelongsTo<DocumentAttachment, $this>
     */
    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_attachment_id');
    }

    /**
     * Readings that went badly and nobody has answered for yet.
     *
     * Two things qualify, and only two. The engine refused the page outright,
     * so there is no text and the row only wants acknowledging; or it kept the
     * page but dropped words out of it, which is the case worth a person's
     * eyes because dropping a word changes what the text says without saying
     * so. A page where every word cleared the floor is not worth anybody's
     * time, and a queue that asks about every upload is one people stop
     * opening (ARC-118).
     *
     * A scope rather than a condition written out wherever it is needed: the
     * review page, the bulk answer and the sidebar count all have to agree
     * about what "waiting" means, and they were three separate copies of this
     * before (ARC-127). `CountIntakeReview` still spells it in SQL, because it
     * is one hand-written round trip for four counts.
     *
     * @param Builder<DocumentAttachment> $query The query being decorated.
     *
     * @return void The scope mutates $query in place.
     */
    public function scopeAwaitingReadingReview(Builder $query): void
    {
        $query
            ->whereNull('ocr_reviewed_at')
            ->where(fn (Builder $unanswered) => $unanswered
                ->where('ocr_status', OcrStatus::PoorlyRead)
                ->orWhere(fn (Builder $partial) => $partial
                    ->where('ocr_status', OcrStatus::Completed)
                    ->whereNotNull('ocr_text')
                    ->where('ocr_text', '!=', '')
                    ->whereColumn('ocr_confident_word_count', '<', 'ocr_word_count')));
    }

    /**
     * Attachments with either kind of finding still waiting on somebody.
     *
     * The review page loads both in one go and sorts them out in PHP, so the
     * OR is expressed once here rather than spelled out at the eager load.
     *
     * @param Builder<DocumentAttachment> $query The query being decorated.
     *
     * @return void The scope mutates $query in place.
     */
    public function scopeAwaitingReview(Builder $query): void
    {
        $query->where(fn (Builder $waiting) => $waiting
            ->where(fn (Builder $reading) => $reading->awaitingReadingReview())
            ->orWhere(fn (Builder $duplicate) => $duplicate->flaggedAsDuplicate()));
    }

    /**
     * Whether this attachment is one `awaitingReadingReview()` would return.
     *
     * The same question asked of a row already in memory, so that a page
     * which loaded both kinds of finding in one query can sort them out
     * without going back to the database. Kept immediately below the scope on
     * purpose: the two say the same thing in two languages and have to be
     * changed together.
     *
     * @return bool True if this reading is still waiting on somebody.
     */
    public function isAwaitingReadingReview(): bool
    {
        if ($this->ocr_reviewed_at !== null) {
            return false;
        }

        if ($this->ocr_status === OcrStatus::PoorlyRead) {
            return true;
        }

        return $this->ocr_status === OcrStatus::Completed
            && filled($this->ocr_text)
            && $this->ocr_word_count !== null
            && (int) $this->ocr_confident_word_count < $this->ocr_word_count;
    }

    /**
     * Attachments still flagged as a copy of something already filed.
     *
     * @param Builder<DocumentAttachment> $query The query being decorated.
     *
     * @return void The scope mutates $query in place.
     */
    public function scopeFlaggedAsDuplicate(Builder $query): void
    {
        $query->whereNotNull('duplicate_of_attachment_id');
    }

    /**
     * Take up a different file, moving the one held now into the version
     * history.
     *
     * Both halves of a replacement in one place, because they are one change
     * and a row that did either on its own would be wrong: archiving without
     * adopting leaves an attachment pointing at a file it no longer claims,
     * and adopting without archiving is the lost original this whole feature
     * exists to prevent (ARC-124).
     *
     * The reading is deliberately not touched here. Whether the new file is
     * read again, and what happens to the document's mirrored text in the
     * meantime, is the caller's to decide — the model cannot see the document's
     * other attachments.
     *
     * @param array{disk: string, path: string, filename: string, mime_type: string, size: int, checksum: string} $file The file to take up.
     * @param string $uploadedBy Id of the user who provided it.
     * @param CarbonInterface|null $uploadedAt When it was provided; now, for a fresh upload.
     *
     * @return DocumentAttachmentVersion The row recording the file that was just superseded.
     */
    public function replaceFileWith(array $file, string $uploadedBy, ?CarbonInterface $uploadedAt = null): DocumentAttachmentVersion
    {
        $superseded = $this->versions()->create([
            'uploaded_by' => $this->uploaded_by,
            'disk' => $this->disk,
            'path' => $this->path,
            'filename' => $this->filename,
            'mime_type' => $this->mime_type,
            'size' => $this->size,
            'checksum' => $this->checksum,
            'uploaded_at' => $this->fileUploadedAt() ?? now(),
            'superseded_at' => now(),
        ]);

        $this->forceFill($file + [
            'uploaded_by' => $uploadedBy,
            'file_uploaded_at' => $uploadedAt ?? now(),
        ])->save();

        return $superseded;
    }

    /**
     * Mark that text extraction has started on this attachment, discarding
     * everything the previous reading produced.
     *
     * `recordOcr()` clears the derived columns too, keyed off this status
     * rather than off the caller, so that every path into extraction voids
     * them and none of them can forget (ARC-122).
     *
     * @return void No return value; persists the status as a side effect.
     */
    public function markOcrProcessing(): void
    {
        $this->recordOcr(OcrStatus::Processing);
    }

    /**
     * Record successfully extracted text.
     *
     * An empty string is a legitimate result — a blank scan has no text — and
     * is still `Completed`, not a failure.
     *
     * @param string $text The extracted text.
     * @param int|null $wordCount Words the engine returned, or null where the text came from a source that does not score itself.
     * @param int|null $confidentWordCount Words it was sure enough of to keep.
     *
     * @return void No return value; persists the text, counts and status as a side effect.
     */
    public function markOcrCompleted(string $text, ?int $wordCount = null, ?int $confidentWordCount = null): void
    {
        $this->recordOcr(OcrStatus::Completed, text: $text, wordCount: $wordCount, confidentWordCount: $confidentWordCount);
    }

    /**
     * Record that this attachment holds nothing text can be extracted from —
     * it is neither a PDF nor an image.
     *
     * @return void No return value; persists the status as a side effect.
     */
    public function markOcrSkipped(): void
    {
        $this->recordOcr(OcrStatus::Skipped);
    }

    /**
     * Record that the page was read but too little of it clearly enough to
     * keep.
     *
     * No text is written, deliberately: `ocr_text` staying null is what keeps
     * the fragment that survived out of the document's mirror, the search
     * index and the duplicate fingerprint (ARC-118).
     *
     * @param int|null $wordCount Words the engine returned.
     * @param int|null $confidentWordCount Words it was sure enough of to keep.
     *
     * @return void No return value; persists the counts and status as a side effect.
     */
    public function markOcrPoorlyRead(?int $wordCount = null, ?int $confidentWordCount = null): void
    {
        $this->recordOcr(OcrStatus::PoorlyRead, wordCount: $wordCount, confidentWordCount: $confidentWordCount);
    }

    /**
     * Record that extraction could not run at all: it is switched off, or the
     * system binaries are missing on this installation.
     *
     * @return void No return value; persists the status as a side effect.
     */
    public function markOcrUnavailable(): void
    {
        $this->recordOcr(OcrStatus::Unavailable);
    }

    /**
     * Record that extraction was attempted and threw.
     *
     * @param string $error The failure message, shown to workspace admins.
     *
     * @return void No return value; persists the error and status as a side effect.
     */
    public function markOcrFailed(string $error): void
    {
        $this->recordOcr(OcrStatus::Failed, error: $error);
    }

    /**
     * Record what the fingerprint pass made of this attachment's text.
     *
     * Both halves land in one write because they are one conclusion: a
     * fingerprint, and whichever earlier attachment it turned out to match.
     *
     * @param int|null $simhash The text's fingerprint, or null if there was too little text to fingerprint.
     * @param DocumentAttachment|null $duplicateOf The earlier attachment this one appears to copy, if any.
     *
     * @return void No return value; saves the model as a side effect.
     */
    public function recordTextFingerprint(?int $simhash, ?self $duplicateOf = null): void
    {
        $this->forceFill([
            'text_simhash' => $simhash,
            'duplicate_of_attachment_id' => $duplicateOf?->id,
        ])->save();
    }

    /**
     * Drop the duplicate flag, because somebody has looked at it and decided to
     * keep both copies. Deliberately permanent: a warning that returns on the
     * next page load has not been dismissed.
     *
     * @return void No return value; saves the model as a side effect.
     */
    public function dismissDuplicate(): void
    {
        $this->forceFill(['duplicate_of_attachment_id' => null])->save();
    }

    /**
     * Record that somebody has read what OCR made of this file and is content
     * to keep it.
     *
     * Only a person can answer this. The engine's own confidence says how sure
     * it was of each word, which is not the same as whether the reading is
     * right — a confident misreading scores as well as a correct one, and only
     * somebody looking at the page can tell them apart (ARC-118).
     *
     * @return void No return value; saves the model as a side effect.
     */
    public function confirmOcr(): void
    {
        // A page the engine refused has no text, so there is nothing to vouch
        // for — the person has seen it and stopped it being counted, which is
        // a weaker claim and recorded as one.
        $this->forceFill([
            'ocr_reviewed_at' => now(),
            'ocr_review_outcome' => blank($this->ocr_text)
                ? OcrReviewOutcome::Acknowledged
                : OcrReviewOutcome::Confirmed,
        ])->save();
    }

    /**
     * Throw away what OCR made of this file, because somebody has read it and
     * it is wrong.
     *
     * The text goes rather than being flagged, which is the whole value of the
     * answer: `ocr_text` is what feeds the search index and the duplicate
     * fingerprint, so a reading nobody believes has to stop being one. The
     * status becomes `PoorlyRead` for the same reason it does when the engine
     * refuses a page itself — the file was read, and what came back was not
     * worth having.
     *
     * The caller is responsible for refreshing the document's mirror
     * afterwards; the attachment cannot see its siblings' text.
     *
     * @return void No return value; saves the model as a side effect.
     */
    public function rejectOcr(): void
    {
        $this->forceFill([
            'ocr_text' => null,
            'ocr_status' => OcrStatus::PoorlyRead,
            'text_simhash' => null,
            'duplicate_of_attachment_id' => null,
            'ocr_reviewed_at' => now(),
            'ocr_review_outcome' => OcrReviewOutcome::Rejected,
        ])->save();
    }

    /**
     * Take this reading off the queue without anybody having read it.
     *
     * The answer for clearing a backlog rather than working through one: after
     * a bulk re-extraction the queue can hold thousands of readings, and
     * asking somebody to confirm each is asking for the box to be ticked
     * without the page being looked at. Recorded as what it is, so nothing
     * downstream can mistake it for a person vouching for the text (ARC-127).
     *
     * @return void No return value; saves the model as a side effect.
     */
    public function dismissOcrReading(): void
    {
        $this->forceFill([
            'ocr_reviewed_at' => now(),
            'ocr_review_outcome' => OcrReviewOutcome::Dismissed,
        ])->save();
    }

    /**
     * Persist an extraction outcome.
     *
     * Uses `forceFill` because the OCR columns are intentionally not fillable;
     * see the note on `casts()`.
     *
     * @param OcrStatus $status The outcome to record.
     * @param string|null $text The extracted text, for a successful run.
     * @param string|null $error The failure message, for a failed run.
     *
     * @return void No return value; saves the model as a side effect.
     */
    private function recordOcr(
        OcrStatus $status,
        ?string $text = null,
        ?string $error = null,
        ?int $wordCount = null,
        ?int $confidentWordCount = null,
    ): void {
        // Starting a reading voids everything derived from the last one: a
        // fingerprint of text about to be replaced, a duplicate match that
        // may not survive the second reading, and a person's verdict on a
        // reading they have not seen. Null on a first extraction anyway.
        $starting = $status === OcrStatus::Processing;

        $this->forceFill([
            'ocr_status' => $status,
            'ocr_text' => $text,
            'ocr_error' => $error,
            'ocr_word_count' => $wordCount,
            'ocr_confident_word_count' => $confidentWordCount,
            'ocr_extracted_at' => $starting ? null : now(),
        ] + ($starting ? [
            'ocr_reviewed_at' => null,
            'ocr_review_outcome' => null,
            'text_simhash' => null,
            'duplicate_of_attachment_id' => null,
        ] : []))->save();
    }
}
