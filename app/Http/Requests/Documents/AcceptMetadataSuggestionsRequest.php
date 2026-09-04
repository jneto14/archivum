<?php

declare(strict_types=1);

namespace App\Http\Requests\Documents;

use Illuminate\Foundation\Http\FormRequest;

class AcceptMetadataSuggestionsRequest extends FormRequest
{
    /**
     * Only the kinds to accept travel in the request; the values they carry are
     * looked up again on the server, so nothing arbitrary can be written
     * through this route.
     *
     * An empty list is valid and means "reviewed, nothing accepted".
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'kinds' => ['present', 'array'],
            'kinds.*' => ['string', 'max:64'],
        ];
    }

    /**
     * @return array<int, string> The kinds of suggestion to accept.
     */
    public function kinds(): array
    {
        /** @var array<int, string> $kinds */
        $kinds = $this->validated('kinds');

        return $kinds;
    }
}
