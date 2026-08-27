<?php

namespace App\Enums;

/**
 * A user's permission level within a workspace.
 */
enum WorkspaceRole: string
{
    case Admin = 'admin';
    case User = 'user';
}
