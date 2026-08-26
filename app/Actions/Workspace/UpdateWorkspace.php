<?php

namespace App\Actions\Workspace;

use App\Models\Workspace;

class UpdateWorkspace
{
    /**
     * Update a Workspace's attributes.
     */
    public function handle(Workspace $workspace, string $name): Workspace
    {
        $workspace->update([
            'name' => $name,
        ]);

        return $workspace;
    }
}
