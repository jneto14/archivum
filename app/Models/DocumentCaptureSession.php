<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\CaptureSessionStatus;
use Database\Factories\DocumentCaptureSessionFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A short-lived pairing between a Document and a phone: the desktop shows a
 * QR code encoding a signed link to this session, and photos taken on the
 * phone are uploaded as attachments to `document` without the phone ever
 * holding an authenticated session of its own.
 *
 * The link's own signature (see `CaptureSessionController::qrCode()`) is what
 * stops a stranger from guessing a session — `status` and `expires_at` exist
 * so the session can also be ended early from either side: the desktop
 * closing the dialog, the phone tapping "done", or simply time running out.
 *
 * @property string $id
 * @property string $document_id
 * @property string $created_by
 * @property CaptureSessionStatus $status
 * @property int $photos_count
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['document_id', 'created_by', 'expires_at'])]
class DocumentCaptureSession extends Model
{
    /** @use HasFactory<DocumentCaptureSessionFactory> */
    use HasFactory, HasUuids;

    /**
     * `status` and `photos_count` are deliberately absent from `#[Fillable]` —
     * they only ever change through the methods below, never from a request.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CaptureSessionStatus::class,
            'expires_at' => 'datetime',
        ];
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
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Whether the phone may still use this session to upload a photo.
     *
     * @return bool True if the session is still `Active` and its expiry hasn't passed.
     */
    public function isActive(): bool
    {
        return $this->status === CaptureSessionStatus::Active && !$this->expires_at->isPast();
    }

    /**
     * Record that a photo was uploaded through this session, so the desktop's
     * poll can tell a new attachment arrived without re-fetching the list on
     * every tick.
     *
     * @return void No return value; persists the incremented count as a side effect.
     */
    public function recordPhoto(): void
    {
        $this->increment('photos_count');
    }

    /**
     * End the session from the desktop side: the user closed the pairing
     * dialog before the phone was done, or before it expired on its own.
     *
     * @return void No return value; persists the status as a side effect.
     */
    public function cancel(): void
    {
        $this->forceFill(['status' => CaptureSessionStatus::Cancelled])->save();
    }

    /**
     * End the session from the phone side: the user tapped "done".
     *
     * @return void No return value; persists the status as a side effect.
     */
    public function complete(): void
    {
        $this->forceFill(['status' => CaptureSessionStatus::Completed])->save();
    }
}
