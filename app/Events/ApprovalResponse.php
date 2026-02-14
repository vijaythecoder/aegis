<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class ApprovalResponse
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(
        public readonly string $requestId,
        public readonly string $decision,
        public readonly bool $remember,
    ) {}
}
