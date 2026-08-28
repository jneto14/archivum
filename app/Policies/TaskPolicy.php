<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;

class TaskPolicy
{
    /**
     * Only workspace admins may list the workspace's tasks.
     *
     * @param User $user The acting user.
     * @param Workspace $workspace The workspace whose tasks are being listed.
     *
     * @return bool True if $user is an admin of $workspace.
     */
    public function viewAny(User $user, Workspace $workspace): bool
    {
        return $workspace->isAdmin($user);
    }

    /**
     * Only workspace admins may trigger a new task (e.g. a document export).
     *
     * @param User $user The acting user.
     * @param Workspace $workspace The workspace the task would run against.
     *
     * @return bool True if $user is an admin of $workspace.
     */
    public function create(User $user, Workspace $workspace): bool
    {
        return $workspace->isAdmin($user);
    }

    /**
     * Only workspace admins may view a task's details, including downloading its result.
     *
     * @param User $user The acting user.
     * @param Task $task The task being viewed.
     *
     * @return bool True if $user is an admin of the task's workspace.
     */
    public function view(User $user, Task $task): bool
    {
        return $task->workspace->isAdmin($user);
    }

    /**
     * Only workspace admins may retry a failed task.
     *
     * @param User $user The acting user.
     * @param Task $task The task being retried.
     *
     * @return bool True if $user is an admin of the task's workspace.
     */
    public function retry(User $user, Task $task): bool
    {
        return $task->workspace->isAdmin($user);
    }
}
