<?php

namespace App\Enums;

enum MemoryType: string
{
    case Fact = 'fact';
    case Preference = 'preference';
    case Note = 'note';
}
