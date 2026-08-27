<?php

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

    public function exceedsUsers(int $currentCount): bool
    {
        return $this->users !== null && $currentCount >= $this->users;
    }

    public function exceedsDocuments(int $currentCount): bool
    {
        return $this->documents !== null && $currentCount >= $this->documents;
    }

    public function exceedsAttachments(int $currentCount): bool
    {
        return $this->attachments !== null && $currentCount >= $this->attachments;
    }

    public function exceedsStorage(int $currentBytes, int $additionalBytes): bool
    {
        return $this->storage_bytes !== null && ($currentBytes + $additionalBytes) > $this->storage_bytes;
    }
}
