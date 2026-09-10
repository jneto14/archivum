<?php

declare(strict_types=1);

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
            // The keys are whatever the workspace types, but a value has to be
            // text. Validating only the array let a null through, and a null
            // value reached the form's folding as `null.normalize()` and took
            // the page down.
            'metadata.*' => ['nullable', 'string'],
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['uuid', 'exists:tags,id'],
        ];
    }
}
