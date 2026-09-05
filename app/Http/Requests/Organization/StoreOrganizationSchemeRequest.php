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

    /**
     * What each level rule says when it fails.
     *
     * The default messages interpolate the attribute name, and a rule on a
     * nested field is named by its path — so a repeated key read "O campo
     * levels.1.key tem um valor duplicado", which is the shape of the request
     * body showing through into the interface.
     *
     * These are shown on the level row they belong to, so none of them needs
     * to say which level it is about. The position on screen already does.
     *
     * @return array<string, string> Rule path to message.
     */
    public function messages(): array
    {
        return [
            'levels.*.name.required' => __('organization.level_name_required'),
            'levels.*.key.required' => __('organization.level_key_required'),
            'levels.*.key.distinct' => __('organization.level_key_duplicate'),
            'levels.*.capacity.integer' => __('organization.level_capacity_invalid'),
            'levels.*.capacity.min' => __('organization.level_capacity_invalid'),
            'levels.*.value_strategy.required' => __('organization.level_value_strategy_required'),
        ];
    }
}
