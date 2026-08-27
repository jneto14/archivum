<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * How an organization level auto-generates the value of its nodes when a
 * new node is created (e.g. a folder label), rather than requiring one to
 * be entered manually.
 */
enum NodeValueStrategy: string
{
    case Manual = 'manual';
    case Sequential = 'sequential';
    case Alphabetical = 'alphabetical';
}
