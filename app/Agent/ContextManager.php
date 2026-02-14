<?php

namespace App\Agent;

use Prism\Prism\ValueObjects\Messages\AssistantMessage;
use Prism\Prism\ValueObjects\Messages\SystemMessage;
use Prism\Prism\ValueObjects\Messages\UserMessage;

class ContextManager
{
    public function __construct(
        private readonly ?ConversationSummarizer $summarizer = null,
        private readonly ?ModelCapabilities $modelCapabilities = null,
    ) {}

    public function estimateTokens(string $text): int
    {
        return max(1, (int) ceil(strlen($text) / 4));
    }

    public function truncateMessages(
        string $systemPrompt,
        array $messages,
        ?int $contextWindowTokens = null,
        ?string $existingSummary = null,
        ?array $memories = null,
        ?string $provider = null,
        ?string $model = null,
    ): array
    {
        $window = $contextWindowTokens;

        if ($window === null && is_string($provider) && $provider !== '' && is_string($model) && $model !== '') {
            $capabilities = $this->modelCapabilities ?? app(ModelCapabilities::class);
            $window = $capabilities->contextWindow($provider, $model);
        }

        $window ??= (int) config('aegis.agent.context_window', 8000);

        return $this->buildContextWindow($systemPrompt, $messages, $window, $existingSummary, $memories);
    }

    public function allocateBudget(int $contextWindow): array
    {
        $systemPrompt = (int) floor($contextWindow * (float) config('aegis.context.system_prompt_budget', 0.15));
        $memories = (int) floor($contextWindow * (float) config('aegis.context.memories_budget', 0.10));
        $summary = (int) floor($contextWindow * (float) config('aegis.context.summary_budget', 0.10));
        $messages = (int) floor($contextWindow * (float) config('aegis.context.messages_budget', 0.60));
        $reserve = (int) floor($contextWindow * (float) config('aegis.context.response_reserve', 0.05));

        $allocated = $systemPrompt + $memories + $summary + $messages + $reserve;
        if ($allocated < $contextWindow) {
            $messages += $contextWindow - $allocated;
        }

        return [
            'system_prompt' => $systemPrompt,
            'memories' => $memories,
            'summary' => $summary,
            'messages' => $messages,
            'reserve' => $reserve,
        ];
    }

    public function compressToolResults(array $messages): array
    {
        return array_map(function (array $message): array {
            $role = (string) ($message['role'] ?? '');
            $toolName = (string) ($message['tool_name'] ?? '');

            if ($role !== 'tool' && $toolName === '') {
                return $message;
            }

            $content = (string) ($message['content'] ?? '');

            if (mb_strlen($content) <= 200) {
                return $message;
            }

            $lines = preg_split('/\R/', $content) ?: [];
            $lineCount = count($lines);
            $firstLine = trim((string) ($lines[0] ?? 'result'));
            $summary = null;

            $decoded = json_decode($content, true);
            if (is_array($decoded)) {
                $keys = array_keys($decoded);
                $keyPreview = implode(', ', array_slice(array_map(static fn ($key): string => (string) $key, $keys), 0, 4));
                $summary = sprintf('JSON result: %d keys (%s)', count($keys), $keyPreview !== '' ? $keyPreview : 'none');
            }

            if ($summary === null && preg_match('/File:\s*([^\n]+)/i', $content, $matches) === 1) {
                $path = trim($matches[1]);
                $type = str_contains($path, '.php') ? 'PHP' : 'text';
                $summary = sprintf('File read: %s (%d lines, %s)', $path, $lineCount, $type);
            }

            if ($summary === null && ($toolName === 'bash' || str_contains(strtolower($firstLine), 'exit:'))) {
                preg_match('/Exit:\s*(\d+)/i', $content, $exitMatches);
                $exitCode = $exitMatches[1] ?? '0';
                $summary = sprintf('Command result: exit %s, %s (%d lines)', $exitCode, $firstLine, $lineCount);
            }

            if ($summary === null) {
                $summary = sprintf('Tool result compressed: %s (%d lines)', $firstLine, $lineCount);
            }

            $message['content'] = $summary;

            return $message;
        }, $messages);
    }

    public function buildContextWindow(
        string $systemPrompt,
        array $messages,
        int $contextWindow,
        ?string $summary = null,
        ?array $memories = null,
    ): array
    {
        $budget = $this->allocateBudget($contextWindow);
        $compressed = $this->compressToolResults($messages);
        $contextMessages = [];

        if (is_array($memories) && $memories !== []) {
            $memoriesText = 'Relevant memories:';

            foreach ($memories as $memory) {
                $memoriesText .= "\n- ".(string) $memory;
            }

            $memoryBudget = $budget['memories'];
            while ($this->estimateTokens($memoriesText) > $memoryBudget && mb_strlen($memoriesText) > 0) {
                $memoriesText = mb_substr($memoriesText, 0, -25);
            }

            if (trim($memoriesText) !== 'Relevant memories:') {
                $contextMessages[] = ['role' => 'system', 'content' => $memoriesText];
            }
        }

        $kept = [];
        $tokens = 0;
        $messageBudget = $budget['messages'];

        for ($index = count($compressed) - 1; $index >= 0; $index--) {
            $message = $compressed[$index];
            $messageTokens = $this->estimateTokens((string) ($message['content'] ?? ''));

            if ($tokens + $messageTokens > $messageBudget) {
                continue;
            }

            $kept[] = $message;
            $tokens += $messageTokens;
        }

        $kept = array_reverse($kept);
        $droppedCount = count($compressed) - count($kept);
        $dropped = $droppedCount > 0 ? array_slice($compressed, 0, $droppedCount) : [];

        if ($summary === null && $this->summarizer !== null && $this->summarizer->shouldSummarize($dropped)) {
            $summary = $this->summarizer->summarize($dropped);
        }

        if (is_string($summary) && trim($summary) !== '') {
            $summaryText = 'Conversation summary: '.trim($summary);
            $summaryBudget = $budget['summary'];

            while ($this->estimateTokens($summaryText) > $summaryBudget && mb_strlen($summaryText) > 0) {
                $summaryText = mb_substr($summaryText, 0, -25);
            }

            if (trim($summaryText) !== 'Conversation summary:') {
                $contextMessages[] = ['role' => 'system', 'content' => $summaryText];
            }
        }

        $contextMessages = array_merge($contextMessages, $kept);

        $windowWithoutReserve = max(0, $contextWindow - $budget['reserve']);

        while ($this->totalTokensUsed($systemPrompt, $contextMessages) > $windowWithoutReserve && count($contextMessages) > 1) {
            $removed = false;

            foreach ($contextMessages as $index => $candidate) {
                if (($candidate['role'] ?? null) !== 'system') {
                    unset($contextMessages[$index]);
                    $contextMessages = array_values($contextMessages);
                    $removed = true;

                    break;
                }
            }

            if (! $removed) {
                break;
            }
        }

        return $contextMessages;
    }

    public function toPrismMessages(array $messages): array
    {
        $prismMessages = [];

        foreach ($messages as $message) {
            $role = (string) ($message['role'] ?? 'user');
            $content = (string) ($message['content'] ?? '');

            if ($role === 'assistant') {
                $prismMessages[] = new AssistantMessage($content);

                continue;
            }

            if ($role === 'system') {
                $prismMessages[] = new SystemMessage($content);

                continue;
            }

            $prismMessages[] = new UserMessage($content);
        }

        return $prismMessages;
    }

    public function totalTokensUsed(string $systemPrompt, array $messages): int
    {
        $messageTokens = array_sum(array_map(
            fn (array $message): int => $this->estimateTokens((string) ($message['content'] ?? '')),
            $messages
        ));

        return $this->estimateTokens($systemPrompt) + $messageTokens;
    }
}
