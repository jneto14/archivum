<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $document_id
 * @property string $tag_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class DocumentTag extends Pivot
{
    use HasUuids;

    protected $table = 'document_tags';
}
