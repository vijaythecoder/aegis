<?php

namespace App\Enums;

enum ToolPermissionLevel: string
{
    case Allow = 'allow';
    case Deny = 'deny';
    case Ask = 'ask';
}
