<?php

declare(strict_types=1);

namespace App\Http\Requests\Organization;

use App\Enums\NodeValueStrategy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrganizationLevelRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'key' => ['required', 'string', 'max:255'],
            'capacity' => ['nullable', 'integer', 'min:1'],
            'value_strategy' => ['required', Rule::enum(NodeValueStrategy::class)],
            'display_settings' => ['nullable', 'array'],
            'metadata' => ['nullable', 'array'],
        ];
    }
}
