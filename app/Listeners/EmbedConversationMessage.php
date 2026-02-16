<?php

namespace App\Listeners;

use App\Enums\MessageRole;
use App\Memory\EmbeddingService;
use App\Memory\VectorStore;
use App\Models\Message;
use Illuminate\Support\Facades\Log;
use Throwable;

class EmbedConversationMessage
{
    private const EMBEDDABLE_ROLES = [
        MessageRole::User,
        MessageRole::Assistant,
    ];

    public function __construct(
        protected EmbeddingService $embeddingService,
        protected VectorStore $vectorStore,
    ) {}

    public function handleMessageCreated(Message $message): void
    {
        if (! in_array($message->role, self::EMBEDDABLE_ROLES, true)) {
            return;
        }

        if (empty(trim($message->content ?? ''))) {
            return;
        }

        try {
            $embedding = $this->embeddingService->embed($message->content);

            if ($embedding === null) {
                return;
            }

            $this->vectorStore->store($embedding, [
                'source_type' => 'message',
                'source_id' => $message->id,
                'content_preview' => mb_substr($message->content, 0, 500),
                'conversation_id' => $message->conversation_id,
            ]);
        } catch (Throwable $e) {
            Log::warning('Failed to embed message', [
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
