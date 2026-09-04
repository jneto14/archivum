<?php

declare(strict_types=1);

namespace App\Http\Requests\Organization;

use Illuminate\Foundation\Http\FormRequest;

class PrintLabelsRequest extends FormRequest
{
    /**
     * Either one node's label, or every node of a level — optionally narrowed to
     * one parent, which is what "the labels for every drawer in this cabinet" is.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'node_id' => ['nullable', 'uuid', 'required_without:level_id', 'prohibits:level_id'],
            'level_id' => ['nullable', 'uuid', 'required_without:node_id'],
            'parent_id' => ['nullable', 'uuid'],
        ];
    }
}
