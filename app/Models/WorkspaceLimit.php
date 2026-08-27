<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\WorkspaceLimitFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $workspace_id
 * @property int|null $storage_bytes
 * @property int|null $users
 * @property int|null $documents
 * @property int|null $attachments
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['workspace_id', 'storage_bytes', 'users', 'documents', 'attachments'])]
class WorkspaceLimit extends Model
{
    /** @use HasFactory<WorkspaceLimitFactory> */
    use HasFactory, HasUuids;

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * Determine whether the given user count has reached or exceeded this workspace's
     * configured user limit. A null limit means the count is unrestricted.
     *
     * @param int $currentCount The workspace's current member count to check against the limit.
     *
     * @return bool True if a limit is configured and currentCount meets or exceeds it.
     */
    public function exceedsUsers(int $currentCount): bool
    {
        return $this->users !== null && $currentCount >= $this->users;
    }

    /**
     * Determine whether the given document count has reached or exceeded this workspace's
     * configured document limit. A null limit means the count is unrestricted.
     *
     * @param int $currentCount The workspace's current document count to check against the limit.
     *
     * @return bool True if a limit is configured and currentCount meets or exceeds it.
     */
    public function exceedsDocuments(int $currentCount): bool
    {
        return $this->documents !== null && $currentCount >= $this->documents;
    }

    /**
     * Determine whether the given attachment count has reached or exceeded this workspace's
     * configured attachment limit. A null limit means the count is unrestricted.
     *
     * @param int $currentCount The workspace's current attachment count to check against the limit.
     *
     * @return bool True if a limit is configured and currentCount meets or exceeds it.
     */
    public function exceedsAttachments(int $currentCount): bool
    {
        return $this->attachments !== null && $currentCount >= $this->attachments;
    }

    /**
     * Determine whether adding the given bytes to current usage would exceed this
     * workspace's configured storage limit. A null limit means usage is unrestricted.
     *
     * @param int $currentBytes The workspace's current storage usage, in bytes.
     * @param int $additionalBytes The number of bytes about to be added (e.g. a new upload's size).
     *
     * @return bool True if a limit is configured and currentBytes + additionalBytes would exceed it.
     */
    public function exceedsStorage(int $currentBytes, int $additionalBytes): bool
    {
        return $this->storage_bytes !== null && ($currentBytes + $additionalBytes) > $this->storage_bytes;
    }
}
