<?php

namespace App\Http\Requests\Documents;

use App\Models\Workspace;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * @property-read Workspace $workspace
 */
class StoreDocumentTypeRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255', Rule::unique('document_types')->where('workspace_id', $this->workspace->id)],
            'key' => ['required', 'string', 'max:255', Rule::unique('document_types')->where('workspace_id', $this->workspace->id)],
        ];
    }
}
