<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Models\Workspace;
use App\Models\WorkspaceLimit;

class UpdateWorkspaceLimit
{
    /**
     * Create or update a Workspace's configured resource limits. A null value
     * for any field means that resource is unlimited.
     *
     * @param Workspace $workspace The workspace whose limits are being set.
     * @param array{storage_bytes: int|null, users: int|null, documents: int|null, attachments: int|null} $attributes The new limit values.
     *
     * @return WorkspaceLimit The workspace's limit record.
     */
    public function handle(Workspace $workspace, array $attributes): WorkspaceLimit
    {
        return WorkspaceLimit::query()->updateOrCreate(
            ['workspace_id' => $workspace->id],
            $attributes,
        );
    }
}
