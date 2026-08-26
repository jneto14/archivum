<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\Pivot;

class DocumentTag extends Pivot
{
    use HasUuids;

    protected $table = 'document_tags';
}
