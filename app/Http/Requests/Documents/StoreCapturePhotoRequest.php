<?php

declare(strict_types=1);

namespace App\Http\Requests\Documents;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;

class StoreCapturePhotoRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * One endpoint serves two actions from the phone — sending photos, and
     * tapping "done" — because both have to land on the exact same signed
     * URL (see routes/capture.php). `files` is only required for the first.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'done' => ['sometimes', 'boolean'],
            'files' => ['required_unless:done,1', 'array', 'min:1'],
            'files.*' => ['file', 'max:51200'],
        ];
    }

    /**
     * Whether the phone is ending the session rather than uploading photos.
     *
     * @return bool
     */
    public function isDone(): bool
    {
        return $this->boolean('done');
    }

    /**
     * The validated uploads as a plain list. Empty when `isDone()` is true.
     *
     * @return list<UploadedFile>
     */
    public function attachments(): array
    {
        return array_values(Arr::wrap($this->file('files')));
    }
}
