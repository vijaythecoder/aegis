<?php

namespace App\Messaging;

use Illuminate\Support\Carbon;

class IncomingMessage
{
    public function __construct(
        public readonly string $platform,
        public readonly string $channelId,
        public readonly string $senderId,
        public readonly string $content,
        public readonly array $mediaUrls = [],
        public readonly ?Carbon $timestamp = null,
        public readonly array $rawPayload = [],
    ) {}
}
