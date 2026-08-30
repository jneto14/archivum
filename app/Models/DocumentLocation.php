<?php

declare(strict_types=1);

namespace App\Models;

use App\Concerns\LogsWorkspaceActivity;
use Database\Factories\DocumentLocationFactory;
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
 * @property string $organization_node_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['document_id', 'organization_node_id'])]
class DocumentLocation extends Model
{
    /** @use HasFactory<DocumentLocationFactory> */
    use HasFactory, HasUuids, LogsWorkspaceActivity;

    /**
     * A location record is never updated or deleted in place — a move creates
     * a new one — so only 'created' is meaningful to log.
     *
     * @var array<int, string>
     */
    protected static $recordEvents = ['created'];

    /**
     * @return LogOptions Logs moves under the 'document_location' log name.
     */
    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('document_location')
            ->logOnly(['organization_node_id'])
            ->dontLogEmptyChanges();
    }

    /**
     * @return string|null This location's document's workspace id.
     */
    protected function resolveActivityWorkspaceId(): ?string
    {
        return $this->document?->workspace_id;
    }

    /**
     * @return string|null The moved document's title and its new physical location path.
     */
    protected function resolveActivityLabel(): ?string
    {
        return "{$this->document->title} → {$this->node->path()}";
    }

    /**
     * @return BelongsTo<Document, $this>
     */
    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class);
    }

    /**
     * @return BelongsTo<OrganizationNode, $this>
     */
    public function node(): BelongsTo
    {
        return $this->belongsTo(OrganizationNode::class, 'organization_node_id');
    }
}
