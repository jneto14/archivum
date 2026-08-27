<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\DocumentLocationFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

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
    use HasFactory, HasUuids;

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
