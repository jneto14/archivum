<?php

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrganizationRuleRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'matcher_key' => ['required', 'string', 'max:255'],
            'matcher_value' => ['required', 'string', 'max:255'],
            'target_level_id' => ['required', 'uuid', 'exists:organization_levels,id'],
            'preferred_value' => ['required', 'string', 'max:255'],
        ];
    }
}
