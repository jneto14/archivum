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
 * @property string|null $ocr_error
 * @property Carbon|null $ocr_extracted_at
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
     *
     * @return void No return value; persists the text and status as a side effect.
     */
    public function markOcrCompleted(string $text): void
    {
        $this->recordOcr(OcrStatus::Completed, text: $text);
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
    private function recordOcr(OcrStatus $status, ?string $text = null, ?string $error = null): void
    {
        $this->forceFill([
            'ocr_status' => $status,
            'ocr_text' => $text,
            'ocr_error' => $error,
            'ocr_extracted_at' => $status === OcrStatus::Processing ? null : now(),
        ])->save();
    }
}
