<?php

declare(strict_types=1);

namespace App\Http\Requests\Documents;

use App\Models\DocumentType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * @property-read DocumentType $documentType
 */
class UpdateDocumentTypeRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'max:255',
                Rule::unique('document_types')
                    ->where('workspace_id', $this->documentType->workspace_id)
                    ->ignore($this->documentType->id),
            ],
            'key' => [
                'required',
                'string',
                'max:255',
                Rule::unique('document_types')
                    ->where('workspace_id', $this->documentType->workspace_id)
                    ->ignore($this->documentType->id),
            ],
        ];
    }
}
