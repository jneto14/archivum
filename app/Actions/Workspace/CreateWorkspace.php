<?php

namespace App\Actions\Workspace;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Support\Facades\DB;

class CreateWorkspace
{
    /**
     * Create a new Workspace and attach the owner as its admin.
     */
    public function handle(User $owner, string $name): Workspace
    {
        return DB::transaction(function () use ($owner, $name): Workspace {
            $workspace = Workspace::query()->create([
                'name' => $name,
            ]);

            WorkspaceUser::query()->create([
                'workspace_id' => $workspace->id,
                'user_id' => $owner->id,
                'role' => WorkspaceRole::Admin,
            ]);

            return $workspace;
        });
    }
}
