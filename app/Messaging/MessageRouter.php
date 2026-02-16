<?php

namespace App\Messaging;

use App\Agent\AegisAgent;
use App\Messaging\Contracts\MessagingAdapter;

class MessageRouter
{
    private array $adapters = [];

    public function __construct(
        private readonly SessionBridge $sessionBridge,
        private readonly ?AegisAgent $agent = null,
    ) {}

    public function route(IncomingMessage $message): string
    {
        $conversation = $this->sessionBridge->resolveConversation(
            $message->platform,
            $message->channelId,
            $message->senderId,
        );

        $agent = $this->agent ?? app(AegisAgent::class);

        $participant = (object) ['id' => $message->senderId];

        return $agent->continue((string) $conversation->id, $participant)->prompt($message->content)->text;
    }

    public function registerAdapter(string $platform, MessagingAdapter $adapter): void
    {
        $this->adapters[strtolower($platform)] = $adapter;
    }

    public function getAdapter(string $platform): ?MessagingAdapter
    {
        return $this->adapters[strtolower($platform)] ?? null;
    }
}
