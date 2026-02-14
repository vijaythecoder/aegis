<?php

namespace App\Messaging;

use App\Agent\AgentOrchestrator;
use App\Messaging\Contracts\MessagingAdapter;

class MessageRouter
{
    private array $adapters = [];

    public function __construct(
        private readonly SessionBridge $sessionBridge,
        private readonly ?AgentOrchestrator $orchestrator = null,
    ) {}

    public function route(IncomingMessage $message): string
    {
        $conversation = $this->sessionBridge->resolveConversation(
            $message->platform,
            $message->channelId,
            $message->senderId,
        );

        $orchestrator = $this->orchestrator ?? app(AgentOrchestrator::class);

        return $orchestrator->respond(
            $message->content,
            $conversation->id,
            null,
            null,
        );
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
