<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A Task record's lifecycle state.
 */
enum TaskStatus: string
{
    case Queued = 'queued';
    case Processing = 'processing';
    case Completed = 'completed';
    case Failed = 'failed';
}
