<?php

declare(strict_types=1);

namespace App\Http\Requests\Documents;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class ReplaceAttachmentRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * One file, unlike `StoreAttachmentRequest`: a replacement is a swap for
     * something that already exists, and a list of them would have no answer
     * to which one wins.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'file' => ['required', 'file', 'max:51200'],
        ];
    }

    /**
     * The validated upload.
     *
     * @return UploadedFile The file replacing the attachment's current one.
     */
    public function replacement(): UploadedFile
    {
        /** @var UploadedFile $file */
        $file = $this->file('file');

        return $file;
    }
}
