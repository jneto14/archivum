<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\Rules\Unique;

/**
 * Shared user profile validation rules, extracted into a trait so profile
 * updates and other profile-writing actions validate consistently.
 */
trait ProfileValidationRules
{
    /**
     * Get the validation rules used to validate user profiles.
     *
     * @param string|null $userId The id of the user being updated, so their own email is excluded
     *                            from the uniqueness check; omit when validating a brand-new profile.
     *
     * @return array<string, array<int, Unique|In|ValidationRule|array<mixed>|string>>
     */
    protected function profileRules(?string $userId = null): array
    {
        return [
            'name' => $this->nameRules(),
            'email' => $this->emailRules($userId),
            'timezone' => $this->timezoneRules(),
            'locale' => $this->localeRules(),
        ];
    }

    /**
     * Get the validation rules used to validate user names.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function nameRules(): array
    {
        return ['required', 'string', 'max:255'];
    }

    /**
     * Get the validation rules used to validate user emails.
     *
     * @param string|null $userId The id of the user being updated, so their own email is excluded
     *                            from the uniqueness check; omit when validating a brand-new profile.
     *
     * @return array<int, Unique|ValidationRule|array<mixed>|string>
     */
    protected function emailRules(?string $userId = null): array
    {
        return [
            'required',
            'string',
            'email',
            'max:255',
            $userId === null
                ? Rule::unique(User::class)
                : Rule::unique(User::class)->ignore($userId),
        ];
    }

    /**
     * Get the validation rules used to validate user timezones.
     *
     * @return array<int, ValidationRule|array<mixed>|string>
     */
    protected function timezoneRules(): array
    {
        return ['nullable', 'timezone'];
    }

    /**
     * Get the validation rules used to validate user locales.
     *
     * @return array<int, In|ValidationRule|array<mixed>|string>
     */
    protected function localeRules(): array
    {
        return ['nullable', 'string', Rule::in(array_keys(config('archivum.locales')))];
    }
}
