<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class ApprovalRequest implements ShouldBroadcast
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public readonly string $requestId;

    public function __construct(
        public readonly string $toolName,
        public readonly string $permission,
        public readonly array $parameters,
        ?string $requestId = null,
    ) {
        $this->requestId = $requestId ?? Str::uuid()->toString();
    }

    public function broadcastOn(): array
    {
        return [new Channel('approval-requests')];
    }

    public function broadcastAs(): string
    {
        return 'approval.requested';
    }
}
