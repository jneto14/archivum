<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\LogsWorkspaceActivity;
use App\Enums\OcrStatus;
use Database\Factories\DocumentAttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
 * @property OcrStatus $ocr_status
 * @property string|null $ocr_text
 * @property int|null $ocr_word_count
 * @property int|null $ocr_confident_word_count
 * @property Carbon|null $ocr_reviewed_at
 * @property string|null $ocr_error
 * @property Carbon|null $ocr_extracted_at
 * @property int|null $text_simhash
 * @property string|null $duplicate_of_attachment_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['document_id', 'uploaded_by', 'disk', 'path', 'filename', 'mime_type', 'size', 'checksum'])]
class DocumentAttachment extends Model
{
    /** @use HasFactory<DocumentAttachmentFactory> */
    use HasFactory, HasUuids, LogsWorkspaceActivity;

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
            'ocr_extracted_at' => 'datetime',
            'ocr_reviewed_at' => 'datetime',
            'text_simhash' => 'integer',
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
     * Mark that text extraction has started on this attachment.
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
        $this->forceFill(['ocr_reviewed_at' => now()])->save();
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
        ])->save();
    }

    /**
     * Record that somebody has seen a page the engine itself refused, so it
     * stops being counted.
     *
     * Handwriting never improves, and without a way out the queue would go on
     * counting a page nobody can do anything more about.
     *
     * @return void No return value; saves the model as a side effect.
     */
    public function dismissOcrReview(): void
    {
        $this->forceFill(['ocr_reviewed_at' => now()])->save();
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
        $this->forceFill([
            'ocr_status' => $status,
            'ocr_text' => $text,
            'ocr_error' => $error,
            'ocr_word_count' => $wordCount,
            'ocr_confident_word_count' => $confidentWordCount,
            'ocr_extracted_at' => $status === OcrStatus::Processing ? null : now(),
        ])->save();
    }
}
