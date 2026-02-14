<?php

namespace App\Enums;

enum AuditLogResult: string
{
    case Allowed = 'allowed';
    case Denied = 'denied';
    case Pending = 'pending';
    case Error = 'error';
}
