<?php

namespace App\Memory;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Prism;
use Throwable;

class ConversationSummaryService
{
    public function __construct(
        private readonly EmbeddingService $embeddingService,
        private readonly VectorStore $vectorStore,
    ) {}

    public function summarize(int $conversationId): ?string
    {
        $conversation = Conversation::query()->find($conversationId);

        if (! $conversation) {
            return null;
        }

        $messages = Message::query()
            ->where('conversation_id', $conversationId)
            ->orderBy('created_at')
            ->limit(100)
            ->get();

        if ($messages->isEmpty()) {
            return null;
        }

        $transcript = $messages->map(fn (Message $m): string => "{$m->role->value}: {$m->content}")
            ->implode("\n");

        $transcript = mb_substr($transcript, 0, 6000);

        $summary = $this->generateSummaryViaLlm($transcript);

        if ($summary === null) {
            return null;
        }

        $conversation->forceFill(['summary' => $summary])->save();

        $this->embedSummary($conversation, $summary);

        return $summary;
    }

    public function summarizeIfStale(int $conversationId, int $staleMinutes = 30): ?string
    {
        $conversation = Conversation::query()->find($conversationId);

        if (! $conversation) {
            return null;
        }

        if (trim((string) $conversation->summary) !== '') {
            $lastMessage = $conversation->last_message_at;

            if ($lastMessage && $lastMessage->diffInMinutes(now()) < $staleMinutes) {
                return $conversation->summary;
            }
        }

        return $this->summarize($conversationId);
    }

    public function summarizeAll(int $staleMinutes = 60): int
    {
        $conversations = Conversation::query()
            ->whereNull('summary')
            ->orWhere('summary', '')
            ->whereHas('messages')
            ->get();

        $count = 0;

        foreach ($conversations as $conversation) {
            if ($this->summarize($conversation->id) !== null) {
                $count++;
            }
        }

        return $count;
    }

    private function generateSummaryViaLlm(string $transcript): ?string
    {
        $provider = (string) (config('aegis.agent.summary_provider') ?: config('aegis.agent.default_provider', 'anthropic'));
        $model = (string) config('aegis.agent.summary_model');

        if ($model === '') {
            $model = (string) config("aegis.providers.{$provider}.default_model", '');
        }

        try {
            $response = Prism::text()
                ->using($provider, $model)
                ->withClientOptions(['timeout' => 20])
                ->withSystemPrompt(implode("\n", [
                    'Summarize this conversation in 2-4 sentences.',
                    'Focus on: key topics discussed, decisions made, and any action items.',
                    'Include specific technical details, project names, or preferences mentioned.',
                    'Return ONLY the summary text.',
                ]))
                ->withPrompt($transcript)
                ->asText();

            $summary = trim($response->text);

            if ($summary === '' || mb_strlen($summary) > 2000) {
                return null;
            }

            return $summary;
        } catch (Throwable $e) {
            Log::debug('Conversation summary generation failed', ['error' => $e->getMessage()]);

            return null;
        }
    }

    private function embedSummary(Conversation $conversation, string $summary): void
    {
        $embedding = $this->embeddingService->embed($summary);

        if ($embedding === null) {
            return;
        }

        $this->vectorStore->store($embedding, [
            'source_type' => 'conversation_summary',
            'source_id' => $conversation->id,
            'content_preview' => mb_substr($summary, 0, 500),
            'conversation_id' => $conversation->id,
        ]);
    }
}
