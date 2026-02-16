<?php

namespace App\Agent\Middleware;

use App\Memory\EmbeddingService;
use App\Memory\HybridSearchService;
use App\Memory\TemporalParser;
use Closure;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Prompts\AgentPrompt;
use Throwable;

class InjectMemoryContext
{
    public function __construct(
        protected HybridSearchService $searchService,
        protected EmbeddingService $embeddingService,
        protected TemporalParser $temporalParser,
    ) {}

    public function handle(AgentPrompt $prompt, Closure $next)
    {
        if (! config('aegis.memory.auto_recall', true)) {
            return $next($prompt);
        }

        $userMessage = $prompt->prompt;

        if (trim($userMessage) === '') {
            return $next($prompt);
        }

        $memoryContext = $this->buildMemoryContext($userMessage);

        if ($memoryContext === '') {
            return $next($prompt);
        }

        return $next($prompt->prepend($memoryContext));
    }

    private function buildMemoryContext(string $userMessage): string
    {
        try {
            $queryEmbedding = $this->embeddingService->embed($userMessage);
            $results = $this->searchService->search($userMessage, $queryEmbedding, 5);

            if ($results->isEmpty()) {
                return '';
            }

            $minScore = 0.3;
            $relevant = $results->filter(fn (array $r): bool => $r['score'] >= $minScore);

            $dateRange = $this->temporalParser->parse($userMessage);

            if ($dateRange !== null) {
                $filtered = $relevant->filter(function (array $r) use ($dateRange): bool {
                    if (! isset($r['conversation_id'])) {
                        return true;
                    }

                    $conversation = \App\Models\Conversation::query()->find($r['conversation_id']);

                    if (! $conversation) {
                        return true;
                    }

                    return $conversation->created_at->between($dateRange['from'], $dateRange['to']);
                });

                if ($filtered->isNotEmpty()) {
                    $relevant = $filtered;
                }
            }

            if ($relevant->isEmpty()) {
                return '';
            }

            $highRelevanceThreshold = 0.9;

            $lines = $relevant->map(function (array $result, int $index) use ($highRelevanceThreshold): string {
                $num = $index + 1;
                $preview = str_replace("\n", ' ', $result['content_preview']);
                $prefix = $result['score'] >= $highRelevanceThreshold ? '⚡ HIGHLY RELEVANT — ' : '';

                return "[{$num}] {$prefix}({$result['source_type']}) {$preview}";
            })->implode("\n");

            $hasHighRelevance = $relevant->contains(fn (array $r): bool => $r['score'] >= $highRelevanceThreshold);

            $header = "## Relevant Memories (auto-recalled)\nThe following memories may be relevant to the user's message:\n{$lines}";

            if ($hasHighRelevance) {
                $header .= "\n\nIMPORTANT: Items marked ⚡ HIGHLY RELEVANT are almost certainly related to this message. Proactively incorporate them into your response — do not wait for the user to ask.";
            }

            return $header;
        } catch (Throwable $e) {
            Log::debug('Memory auto-recall failed', ['error' => $e->getMessage()]);

            return '';
        }
    }
}
