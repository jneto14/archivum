<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * A DocumentCaptureSession's lifecycle state.
 *
 * There is no `Expired` case. Expiry is a function of `expires_at` and the
 * current time, not a state anything transitions into — persisting it would
 * mean either a scheduled sweep to flip rows, or every reader re-deriving the
 * same check `isActive()` already makes. `Active` plus `expires_at` in the
 * past means expired; nothing needs to write that down.
 */
enum CaptureSessionStatus: string
{
    /** Open: the phone may still upload photos through it. */
    case Active = 'active';

    /** Ended deliberately from the desktop side, before it expired. */
    case Cancelled = 'cancelled';

    /** Ended deliberately from the phone side, after at least one upload. */
    case Completed = 'completed';
}
