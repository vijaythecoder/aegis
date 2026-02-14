<?php

namespace App\Agent;

class StreamingOrchestrator
{
    public function __construct(private readonly AgentOrchestrator $orchestrator) {}

    public function start(string $message, int $conversationId): string
    {
        $buffer = new StreamBuffer((string) $conversationId);

        return $this->orchestrator->respondStreaming($message, $conversationId, $buffer);
    }

    public function stop(int $conversationId): void
    {
        $buffer = new StreamBuffer((string) $conversationId);
        $buffer->cancel();
    }

    public function buffer(int $conversationId): StreamBuffer
    {
        return new StreamBuffer((string) $conversationId);
    }
}
