<?php

namespace App\Memory;

use App\Enums\MessageRole;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Collection;

class MessageService
{
    public function store(int $conversationId, MessageRole $role, string $content, ?array $toolData = null): Message
    {
        $conversation = Conversation::query()->findOrFail($conversationId);

        $message = Message::query()->create([
            'conversation_id' => $conversationId,
            'role' => $role,
            'content' => $content,
            'tool_name' => $toolData['tool_name'] ?? null,
            'tool_call_id' => $toolData['tool_call_id'] ?? null,
            'tool_result' => $toolData['tool_result'] ?? $toolData,
            'tokens_used' => $this->estimateTokens($content),
        ]);

        $conversation->forceFill(['last_message_at' => now()])->save();

        return $message;
    }

    public function loadHistory(int $conversationId, ?int $limit = null): Collection
    {
        $baseQuery = Message::query()
            ->where('conversation_id', $conversationId)
            ->orderBy('created_at')
            ->orderBy('id');

        if ($limit === null || $limit <= 0) {
            return $baseQuery->get();
        }

        return Message::query()
            ->where('conversation_id', $conversationId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->sortBy(['created_at', 'id'])
            ->values();
    }

    public function tokenCount(int $conversationId): int
    {
        return (int) Message::query()
            ->where('conversation_id', $conversationId)
            ->sum('tokens_used');
    }

    private function estimateTokens(string $content): int
    {
        $words = str_word_count($content);

        if ($words > 0) {
            return max(1, $words);
        }

        return max(1, (int) ceil(mb_strlen($content) / 4));
    }
}
