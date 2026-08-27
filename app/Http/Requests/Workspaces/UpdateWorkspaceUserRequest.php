<?php

namespace App\Http\Requests\Workspaces;

use App\Enums\WorkspaceRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateWorkspaceUserRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', Rule::enum(WorkspaceRole::class)],
        ];
    }
}
