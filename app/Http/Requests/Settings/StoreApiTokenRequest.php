<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use App\Enums\ApiTokenLifetime;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreApiTokenRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * `expires_in` is `sometimes` rather than `required` so a form submitted
     * without it still issues a token — with the default lifetime, which is
     * the safe end of that fallback. See ApiTokenLifetime.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'expires_in' => ['sometimes', Rule::enum(ApiTokenLifetime::class)],
        ];
    }

    /**
     * How long the token being issued should last.
     *
     * @return ApiTokenLifetime The chosen lifetime, or the default when none was submitted.
     */
    public function lifetime(): ApiTokenLifetime
    {
        return ApiTokenLifetime::fromRequestValue($this->validated('expires_in'));
    }
}
