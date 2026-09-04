<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a learned intake label stands with the workspace that was offered it.
 *
 * Rejection is recorded rather than simply not accepted, because the mining
 * that proposed a phrase once will propose it again on every later run. Without
 * a no that sticks, an admin would spend the rest of the archive's life turning
 * down the same word.
 */
enum IntakeLabelStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Rejected = 'rejected';
}
