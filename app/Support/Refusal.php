<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Validation\ValidationException;

/**
 * A write refused for a reason that is not about any one field the user filled
 * in — a workspace limit, a rule about what may be deleted, a destination that
 * belongs to somebody else.
 *
 * ## Why this exists
 *
 * These refusals are raised as validation errors, which is right: they belong
 * to the request that attempted the write, and Inertia hands them back to the
 * page that made it. The trouble is that a validation error is addressed to a
 * *field*, and the page only renders the ones it has an input for.
 *
 * `CreateDocument` keyed its document-limit message to `workspace`, and no form
 * in the application has a field by that name. The message arrived, nothing
 * read it, and it was dropped — so filing a document into a full workspace did
 * nothing at all and said nothing at all. Reaching a limit is the moment an
 * installation most needs to explain itself, and it was the one moment it
 * could not.
 *
 * Five other guards were addressed to fields that no page renders either. That
 * they were invisible and the rest were not is luck: nothing connected the key
 * an action picked to the inputs a page happens to have.
 *
 * ## The rule
 *
 * A message about something the user typed keeps that input's name, so it can
 * appear beside it. Everything else comes through here, under one reserved key
 * that `PageContainer` always renders — so a refusal cannot be addressed to
 * nobody, whichever page it happens on and whoever writes the next guard.
 *
 * `ActionRefusalsAreVisibleTest` holds every guard in the application to that.
 */
final class Refusal
{
    /**
     * The key these messages travel under.
     *
     * Not a field name, and deliberately not one any form could plausibly use:
     * it is read by the page frame rather than by an input.
     */
    public const string KEY = 'general';

    /**
     * Refuse the write, with a reason to show whoever attempted it.
     *
     * @param string $reason The message, already translated.
     *
     * @return ValidationException To be thrown by the caller, so the refusal reads as one at the call site.
     */
    public static function because(string $reason): ValidationException
    {
        return ValidationException::withMessages([self::KEY => $reason]);
    }
}
