<?php

declare(strict_types=1);

namespace App\Actions\Concerns;

use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

trait FlashesValidationFailure
{
    /**
     * Flash a toast and throw the validation failure it repeats.
     *
     * The field a rule like this fails on — 'workspace', 'node', a rule's own
     * `target_level_id` — is rarely one a page renders a field-level error
     * against, so the exception alone would arrive and be dropped. This
     * flashes the same message as a toast before throwing.
     *
     * @param string $field The error bag key $message is reported under.
     * @param string $message The translated failure message.
     *
     * @throws ValidationException Always.
     */
    private function flashAndFail(string $field, string $message): never
    {
        Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

        throw ValidationException::withMessages([$field => $message]);
    }
}
