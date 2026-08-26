<?php

namespace App\Enums;

enum NodeValueStrategy: string
{
    case Manual = 'manual';
    case Sequential = 'sequential';
    case Alphabetical = 'alphabetical';
}
