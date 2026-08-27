<?php

namespace App\Http\Requests\Documents;

use Illuminate\Foundation\Http\FormRequest;

class UpdateDocumentRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'document_type_id' => ['required', 'uuid', 'exists:document_types,id'],
            'title' => ['required', 'string', 'max:255'],
            'document_date' => ['nullable', 'date'],
            'metadata' => ['nullable', 'array'],
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['uuid', 'exists:tags,id'],
        ];
    }
}
