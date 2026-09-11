<?php

declare(strict_types=1);

namespace App\Http\Requests\Documents;

use App\Enums\BulkReviewAction;
use App\Enums\ReviewFilter;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BulkReviewRequest extends FormRequest
{
    /**
     * An answer given for many documents at once.
     *
     * `documents` absent means "everything the filter matches", which is what
     * somebody clearing a queue of four thousand is actually asking for and
     * not something a request body should have to spell out. The filter
     * travels with it so the server resolves that set itself, rather than
     * trusting a list the page assembled.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'action' => ['required', Rule::in(BulkReviewAction::acceptedValues())],
            'filter' => ['nullable', Rule::in(ReviewFilter::acceptedValues())],
            'documents' => ['nullable', 'array'],
            'documents.*' => ['string', 'uuid'],
        ];
    }

    /**
     * @return BulkReviewAction What to do with the selection.
     */
    public function action(): BulkReviewAction
    {
        return BulkReviewAction::from((string) $this->validated('action'));
    }

    /**
     * @return ReviewFilter The filter the selection was made against.
     */
    public function filter(): ReviewFilter
    {
        $filter = $this->validated('filter');

        return ReviewFilter::fromRequestValue(is_string($filter) ? $filter : null);
    }

    /**
     * @return list<string>|null The documents picked by hand, or null for everything the filter matches.
     */
    public function documentIds(): ?array
    {
        $documents = $this->validated('documents');

        if (!is_array($documents) || $documents === []) {
            return null;
        }

        return array_values(array_map(strval(...), $documents));
    }
}
