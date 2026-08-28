<?php

declare(strict_types=1);

namespace App\Http\Requests\Workspaces;

use Illuminate\Foundation\Http\FormRequest;

class UpdateWorkspaceLimitRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'storage_bytes' => ['nullable', 'integer', 'min:0'],
            'users' => ['nullable', 'integer', 'min:1'],
            'documents' => ['nullable', 'integer', 'min:0'],
            'attachments' => ['nullable', 'integer', 'min:0'],
        ];
    }
}
