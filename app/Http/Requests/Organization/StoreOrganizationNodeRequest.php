<?php

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrganizationNodeRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'level_id' => ['required', 'uuid', 'exists:organization_levels,id'],
            'parent_id' => ['nullable', 'uuid', 'exists:organization_nodes,id'],
            'value' => ['nullable', 'string', 'max:255'],
        ];
    }
}
