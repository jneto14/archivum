<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Workspace;

class ActivityPolicy
{
    /**
     * Only workspace admins may view the workspace's activity feed — it
     * exposes what every member has done, not just the viewer's own actions.
     *
     * @param User $user The acting user.
     * @param Workspace $workspace The workspace whose activity feed is being viewed.
     *
     * @return bool True if $user is an admin of $workspace.
     */
    public function viewAny(User $user, Workspace $workspace): bool
    {
        return $workspace->isAdmin($user);
    }
}
