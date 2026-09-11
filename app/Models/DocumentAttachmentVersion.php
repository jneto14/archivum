<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DocumentAttachmentVersionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A file a DocumentAttachment used to hold, kept after something replaced it.
 *
 * Only superseded files live here; the current one stays on the attachment
 * itself. That asymmetry is the point of the design — it leaves every query
 * about "the attachment's file" reading one row, exactly as it did before
 * versions existed (ARC-124).
 *
 * There are no OCR columns and no soft deletes. A superseded reading is not
 * kept, so nothing here is searchable or fingerprintable; and a version has no
 * life of its own to be trashed or restored from — it goes to the trash and
 * comes back with the attachment holding it.
 *
 * @property string $id
 * @property string $document_attachment_id
 * @property string $uploaded_by
 * @property string $disk
 * @property string $path
 * @property string $filename
 * @property string $mime_type
 * @property int $size
 * @property string $checksum
 * @property Carbon $uploaded_at
 * @property Carbon $superseded_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable([
    'document_attachment_id',
    'uploaded_by',
    'disk',
    'path',
    'filename',
    'mime_type',
    'size',
    'checksum',
    'uploaded_at',
    'superseded_at',
])]
class DocumentAttachmentVersion extends Model
{
    /** @use HasFactory<DocumentAttachmentVersionFactory> */
    use HasFactory, HasUuids;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'size' => 'integer',
            'uploaded_at' => 'datetime',
            'superseded_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<DocumentAttachment, $this>
     */
    public function attachment(): BelongsTo
    {
        return $this->belongsTo(DocumentAttachment::class, 'document_attachment_id');
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Whether this version is one the browser may render inline.
     *
     * Answered from the same list the attachment uses, because an earlier
     * version is served through the same preview endpoint and an uploaded
     * `page.html` is no safer for having been replaced since (ARC-95).
     *
     * @return bool True if the file may be served inline rather than as a download.
     */
    public function isPreviewable(): bool
    {
        return in_array($this->mime_type, DocumentAttachment::INLINE_SAFE_TYPES, true);
    }
}
