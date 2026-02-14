<?php

namespace App\Agent;

use App\Models\Conversation;
use Closure;
use Prism\Prism\Facades\Prism;

class ConversationSummarizer
{
    public function __construct(private readonly ?Closure $llmInvoker = null) {}

    public function summarize(array $messages, ?Closure $llmInvoker = null): string
    {
        if ($messages === []) {
            return '';
        }

        $transcript = collect($messages)
            ->map(function (array $message): string {
                $role = (string) ($message['role'] ?? 'user');
                $content = trim((string) ($message['content'] ?? ''));

                return sprintf('%s: %s', $role, mb_substr($content, 0, 400));
            })
            ->implode("\n");

        $instruction = implode("\n", [
            'Summarize the dropped conversation context for future turns.',
            'Output concise plain text with these sections:',
            'Key decisions:',
            'Facts learned:',
            'Open loops:',
            'Keep it under 180 words and preserve concrete details.',
            '',
            'Conversation chunk:',
            $transcript,
        ]);

        if ($llmInvoker !== null) {
            return trim((string) $llmInvoker($instruction));
        }

        if ($this->llmInvoker !== null) {
            return trim((string) ($this->llmInvoker)($instruction));
        }

        return trim((string) Prism::text()
            ->using(
                (string) config('aegis.agent.summary_provider', config('aegis.agent.default_provider', 'anthropic')),
                (string) config('aegis.agent.summary_model', 'claude-3-5-haiku-latest')
            )
            ->withClientOptions(['timeout' => (int) config('aegis.agent.timeout', 120)])
            ->withPrompt($instruction)
            ->asText()
            ->text);
    }

    public function shouldSummarize(array $droppedMessages): bool
    {
        return count($droppedMessages) > 10;
    }

    public function updateConversationSummary(int $conversationId, string $summary): void
    {
        Conversation::query()
            ->whereKey($conversationId)
            ->update(['summary' => trim($summary)]);
    }
}
