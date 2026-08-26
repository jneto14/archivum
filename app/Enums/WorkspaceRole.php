<?php

namespace App\Enums;

enum WorkspaceRole: string
{
    case Admin = 'admin';
    case User = 'user';
}
