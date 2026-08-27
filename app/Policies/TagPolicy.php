<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Tag;
use App\Models\User;
use App\Models\Workspace;

class TagPolicy
{
    /**
     * Determine whether the user may list tags in the given workspace.
     *
     * @param User $user The acting user.
     * @param Workspace $workspace The workspace whose tags are being listed.
     *
     * @return bool True if $user is a member of $workspace.
     */
    public function viewAny(User $user, Workspace $workspace): bool
    {
        return $workspace->isMember($user);
    }

    /**
     * Determine whether the user may view the given tag.
     *
     * @param User $user The acting user.
     * @param Tag $tag The tag being viewed.
     *
     * @return bool True if $user is a member of $tag's workspace.
     */
    public function view(User $user, Tag $tag): bool
    {
        return $tag->workspace->isMember($user);
    }

    /**
     * Determine whether the user may create a tag in the given workspace.
     *
     * @param User $user The acting user.
     * @param Workspace $workspace The workspace the tag would be created in.
     *
     * @return bool True if $user is a member of $workspace.
     */
    public function create(User $user, Workspace $workspace): bool
    {
        return $workspace->isMember($user);
    }

    /**
     * Determine whether the user may rename the given tag.
     *
     * @param User $user The acting user.
     * @param Tag $tag The tag being updated.
     *
     * @return bool True if $user is a member of $tag's workspace.
     */
    public function update(User $user, Tag $tag): bool
    {
        return $tag->workspace->isMember($user);
    }

    /**
     * Determine whether the user may delete the given tag.
     *
     * @param User $user The acting user.
     * @param Tag $tag The tag being deleted.
     *
     * @return bool True if $user is a member of $tag's workspace.
     */
    public function delete(User $user, Tag $tag): bool
    {
        return $tag->workspace->isMember($user);
    }
}
