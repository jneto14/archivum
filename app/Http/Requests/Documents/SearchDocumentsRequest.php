<?php

declare(strict_types=1);

namespace App\Http\Requests\Documents;

use App\Enums\SearchMode;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SearchDocumentsRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:255'],
            'mode' => ['nullable', Rule::enum(SearchMode::class)],
            'document_type_id' => ['nullable', 'uuid', 'exists:document_types,id'],
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['uuid', 'exists:tags,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ];
    }
}
