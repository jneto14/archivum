<?php

declare(strict_types=1);

namespace App\Http\Requests\Documents;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;

class StoreAttachmentRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * Always a list, even for a single file: one shape means one code path, and
     * the only caller is the attachments card on the document page.
     *
     * A file that fails these rules fails the whole request — nothing in the
     * batch is stored. Same policy as the workspace limits in `UploadAttachment`,
     * so a rejected upload never leaves the document half changed.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'files' => ['required', 'array', 'min:1'],
            'files.*' => ['required', 'file', 'max:51200'],
        ];
    }

    /**
     * The validated uploads as a plain list.
     *
     * `file()` is typed loosely enough to return a single upload or null, so
     * the shape is settled here once rather than at the call site.
     *
     * @return list<UploadedFile> The uploaded files, in the order they were sent.
     */
    public function attachments(): array
    {
        return array_values(Arr::wrap($this->file('files')));
    }
}
