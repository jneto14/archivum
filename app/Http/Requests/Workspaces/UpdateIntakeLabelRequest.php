<?php

declare(strict_types=1);

namespace App\Http\Requests\Workspaces;

use App\Enums\IntakeLabelStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateIntakeLabelRequest extends FormRequest
{
    /**
     * Accepting and rejecting are the same write, so they are one rule.
     * `pending` is deliberately not among them: nothing puts a candidate back
     * to unanswered, and a request that could would let an admin undo somebody
     * else's decision by accident.
     *
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'status' => [
                'required',
                Rule::in([
                    IntakeLabelStatus::Accepted->value,
                    IntakeLabelStatus::Rejected->value,
                ]),
            ],
        ];
    }
}
