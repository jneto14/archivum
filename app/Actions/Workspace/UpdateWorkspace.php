<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Models\Workspace;

class UpdateWorkspace
{
    /**
     * Update a Workspace's attributes.
     *
     * @param Workspace $workspace The workspace to update.
     * @param string $name The workspace's new name.
     *
     * @return Workspace The updated workspace.
     */
    public function handle(Workspace $workspace, string $name): Workspace
    {
        $workspace->update([
            'name' => $name,
        ]);

        return $workspace;
    }
}
