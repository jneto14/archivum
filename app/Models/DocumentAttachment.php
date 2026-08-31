<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\LogsWorkspaceActivity;
use App\Enums\OcrStatus;
use Database\Factories\DocumentAttachmentFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
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
     * Attachments are only ever uploaded or removed — they're never edited in
     * place — so there's no 'updated' event worth recording.
     *
     * @var array<int, string>
     */
    protected static $recordEvents = ['created', 'deleted'];

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
