<?php

declare(strict_types=1);

namespace App\Http\Requests\Organization;

use App\Enums\NodeValueStrategy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreOrganizationSchemeRequest extends FormRequest
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
            'levels' => ['required', 'array', 'min:1'],
            'levels.*.name' => ['required', 'string', 'max:255'],
            'levels.*.key' => ['required', 'string', 'max:255', 'distinct'],
            'levels.*.capacity' => ['nullable', 'integer', 'min:1'],
            'levels.*.has_printable_label' => ['nullable', 'boolean'],
            'levels.*.value_strategy' => ['required', Rule::enum(NodeValueStrategy::class)],
            'levels.*.display_settings' => ['nullable', 'array'],
            'levels.*.metadata' => ['nullable', 'array'],
        ];
    }
}
