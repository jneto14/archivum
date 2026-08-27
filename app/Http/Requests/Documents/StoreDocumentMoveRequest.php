<?php

namespace App\Http\Requests\Documents;

use Illuminate\Foundation\Http\FormRequest;

class StoreDocumentMoveRequest extends FormRequest
{
    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'node_id' => ['nullable', 'uuid', 'exists:organization_nodes,id', 'required_without:scheme_id', 'prohibits:scheme_id'],
            'scheme_id' => ['nullable', 'uuid', 'exists:organization_schemes,id', 'required_without:node_id', 'prohibits:node_id'],
            'criteria' => ['nullable', 'array'],
            'criteria.*' => ['string'],
        ];
    }
}
