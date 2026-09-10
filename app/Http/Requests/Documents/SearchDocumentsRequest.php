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
            // Not `Rule::enum`, because the values this enum answered to before
            // ARC-125 still have to resolve: a bookmarked search URL carrying
            // `mode=exact` is something docs/search.md promises keeps working.
            // Anything that is neither current nor legacy is still rejected
            // rather than silently searched some other way.
            'mode' => ['nullable', Rule::in(SearchMode::acceptedValues())],
            'document_type_id' => ['nullable', 'uuid', 'exists:document_types,id'],
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => ['uuid', 'exists:tags,id'],
            'node_id' => ['nullable', 'uuid', 'exists:organization_nodes,id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ];
    }
}
