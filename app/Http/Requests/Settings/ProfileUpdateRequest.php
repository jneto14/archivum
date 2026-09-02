<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Concerns\ProfileValidationRules;
use App\Support\DemoMode;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\Validator;

class ProfileUpdateRequest extends FormRequest
{
    use ProfileValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, Unique|In|ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return $this->profileRules($this->user()->id);
    }

    /**
     * Refuse an email change on a demo installation.
     *
     * Rejected here rather than by putting DenyInDemoMode on the route,
     * because this one form also carries the name, the language and the
     * timezone — and switching the interface to Portuguese is a thing a
     * visitor to the demo should be able to try. Blocking the whole route to
     * protect one field would take the other three with it.
     *
     * The email is the field that matters: it is the login the demo prints on
     * its own front page, and a visitor who changes it locks out everyone who
     * arrives before the nightly reset. The same lock-out the password
     * restriction already prevents, through a second door.
     *
     * @return array<int, callable(Validator): void> The additional checks run once the rules above pass.
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (!DemoMode::enabled()) {
                    return;
                }

                if ($this->input('email') === $this->user()->email) {
                    return;
                }

                $validator->errors()->add('email', __('demo.action_unavailable'));
            },
        ];
    }
}
