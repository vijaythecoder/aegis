<?php

namespace App\Agent;

use App\Jobs\ExtractMemoriesJob;
use App\Jobs\SummarizeConversationJob;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Collection;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Messages\Message as SdkMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;

class AegisConversationStore implements ConversationStore
{
    public function latestConversationId(string|int $userId): ?string
    {
        $conversation = Conversation::query()
            ->orderByDesc('last_message_at')
            ->first();

        return $conversation ? (string) $conversation->id : null;
    }

    public function storeConversation(string|int|null $userId, string $title): string
    {
        $conversation = Conversation::create([
            'title' => $title,
            'last_message_at' => now(),
        ]);

        return (string) $conversation->id;
    }

    public function storeUserMessage(string $conversationId, string|int|null $userId, AgentPrompt $prompt): string
    {
        $message = Message::create([
            'conversation_id' => (int) $conversationId,
            'role' => 'user',
            'content' => $prompt->prompt,
        ]);

        return (string) $message->id;
    }

    public function storeAssistantMessage(string $conversationId, string|int|null $userId, AgentPrompt $prompt, AgentResponse $response): string
    {
        $totalTokens = ($response->usage?->promptTokens ?? 0) + ($response->usage?->completionTokens ?? 0);

        $message = Message::create([
            'conversation_id' => (int) $conversationId,
            'role' => 'assistant',
            'content' => $response->text,
            'tokens_used' => $totalTokens > 0 ? $totalTokens : null,
        ]);

        Conversation::where('id', (int) $conversationId)
            ->update(['last_message_at' => now()]);

        return (string) $message->id;
    }

    /**
     * @return Collection<int, SdkMessage>
     */
    public function getLatestConversationMessages(string $conversationId, int $limit): Collection
    {
        $this->extractFromPrunedMessages((int) $conversationId, $limit);

        return Message::query()
            ->where('conversation_id', (int) $conversationId)
            ->orderByDesc('id')
            ->limit($limit)
            ->get()
            ->reverse()
            ->values()
            ->map(fn (Message $m) => new SdkMessage($m->role->value, $m->content));
    }

    private function extractFromPrunedMessages(int $conversationId, int $limit): void
    {
        $totalCount = Message::query()
            ->where('conversation_id', $conversationId)
            ->count();

        if ($totalCount <= $limit) {
            return;
        }

        $prunedMessages = Message::query()
            ->where('conversation_id', $conversationId)
            ->orderBy('id')
            ->limit($totalCount - $limit)
            ->get();

        $pairs = [];
        $currentUser = null;

        foreach ($prunedMessages as $message) {
            if ($message->role->value === 'user') {
                $currentUser = $message->content;
            } elseif ($message->role->value === 'assistant' && $currentUser !== null) {
                $pairs[] = [$currentUser, $message->content];
                $currentUser = null;
            }
        }

        foreach ($pairs as [$userMsg, $assistantMsg]) {
            ExtractMemoriesJob::dispatch($userMsg, $assistantMsg, $conversationId);
        }

        SummarizeConversationJob::dispatch($conversationId);
    }
}
