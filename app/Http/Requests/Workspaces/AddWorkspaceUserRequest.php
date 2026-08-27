<?php

declare(strict_types=1);

namespace App\Http\Requests\Workspaces;

use App\Enums\WorkspaceRole;
use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AddWorkspaceUserRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'name' => [
                Rule::requiredIf(fn () => !User::query()->where('email', $this->input('email'))->exists()),
                'nullable',
                'string',
                'max:255',
            ],
            'role' => ['required', Rule::enum(WorkspaceRole::class)],
        ];
    }
}
