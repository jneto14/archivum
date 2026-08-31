<?php

declare(strict_types=1);

namespace App\Actions\Workspace;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceUser;
use Illuminate\Validation\ValidationException;

class AddWorkspaceUser
{
    public function __construct(private readonly CalculateWorkspaceUsage $calculateUsage) {}

    /**
     * Add a user to a Workspace with the given role.
     *
     * @param Workspace $workspace The workspace to add the user to.
     * @param User $user The user being added.
     * @param WorkspaceRole $role The role to assign the user within the workspace.
     *
     * @return WorkspaceUser The newly created membership record.
     *
     * @throws ValidationException If $user is already a member of $workspace, or the workspace has reached its user limit.
     */
    public function handle(Workspace $workspace, User $user, WorkspaceRole $role): WorkspaceUser
    {
        if ($workspace->isMember($user)) {
            throw ValidationException::withMessages([
                'email' => __('workspace.member_already_exists'),
            ]);
        }

        if ($workspace->limits?->exceedsUsers($this->calculateUsage->users($workspace))) {
            throw ValidationException::withMessages([
                'email' => __('workspace.user_limit_reached'),
            ]);
        }

        $member = WorkspaceUser::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'role' => $role,
        ]);

        $this->calculateUsage->forget($workspace);

        return $member;
    }
}
