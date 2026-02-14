<?php

namespace App\Security;

enum PermissionDecision
{
    case Allowed;
    case Denied;
    case NeedsApproval;
}
