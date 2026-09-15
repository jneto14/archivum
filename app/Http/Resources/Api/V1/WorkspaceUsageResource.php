<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * What a workspace is using, against what it is allowed.
 *
 * A null limit means there isn't one, which is not the same as a limit of
 * zero — so it is reported as null rather than folded into a number.
 *
 * @mixin Workspace
 */
class WorkspaceUsageResource extends JsonResource
{
    /**
     * @param array{storage_bytes: int, users: int, documents: int, attachments: int} $usage What the workspace currently uses.
     */
    public function __construct(Workspace $workspace, private readonly array $usage)
    {
        parent::__construct($workspace);
    }

    /**
     * @param Request $request The incoming request.
     *
     * @return array<string, array{used: int, limit: int|null}> Each metric's usage and ceiling.
     */
    public function toArray(Request $request): array
    {
        $limits = $this->limits;

        return [
            'storage' => ['used' => $this->usage['storage_bytes'], 'limit' => $limits?->storage_bytes],
            'users' => ['used' => $this->usage['users'], 'limit' => $limits?->users],
            'documents' => ['used' => $this->usage['documents'], 'limit' => $limits?->documents],
            'attachments' => ['used' => $this->usage['attachments'], 'limit' => $limits?->attachments],
        ];
    }
}
