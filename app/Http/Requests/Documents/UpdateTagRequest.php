<?php

declare(strict_types=1);

namespace App\Http\Requests\Documents;

use App\Models\Tag;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * @property-read Tag $tag
 */
class UpdateTagRequest extends FormRequest
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
                Rule::unique('tags')
                    ->where('workspace_id', $this->tag->workspace_id)
                    ->ignore($this->tag->id),
            ],
        ];
    }
}
