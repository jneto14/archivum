<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What a bulk answer on the review queue does.
 *
 * Three, and deliberately not five. Confirming a reading and refusing one are
 * both absent: confirming asserts that a person read the text, which is the
 * one claim ARC-118 exists to require and the one a bulk action cannot
 * honestly make, and refusing *deletes* the text along with the fingerprint.
 * Both stay one at a time, with the page in front of somebody.
 *
 * What bulk offers instead is `DismissReadings` — off the queue, recorded as
 * nobody having read it.
 */
enum BulkReviewAction: string
{
    /** Write each document's suggested values and take it off the queue. */
    case AcceptSuggestions = 'accept_suggestions';

    /** Stop asking about these readings, without claiming anybody read them. */
    case DismissReadings = 'dismiss_readings';

    /** Keep both copies, on every flagged attachment in the selection. */
    case DismissDuplicates = 'dismiss_duplicates';

    /**
     * @return list<string> Every value the request may carry.
     */
    public static function acceptedValues(): array
    {
        return array_column(self::cases(), 'value');
    }
}
