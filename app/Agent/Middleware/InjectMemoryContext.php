<?php

namespace App\Agent\Middleware;

use App\Memory\EmbeddingService;
use App\Memory\HybridSearchService;
use Closure;
use Illuminate\Support\Facades\Log;
use Laravel\Ai\Prompts\AgentPrompt;
use Throwable;

class InjectMemoryContext
{
    public function __construct(
        protected HybridSearchService $searchService,
        protected EmbeddingService $embeddingService,
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

            if ($relevant->isEmpty()) {
                return '';
            }

            $lines = $relevant->map(function (array $result, int $index): string {
                $num = $index + 1;
                $preview = str_replace("\n", ' ', $result['content_preview']);

                return "[{$num}] ({$result['source_type']}) {$preview}";
            })->implode("\n");

            return "## Relevant Memories (auto-recalled)\nThe following memories may be relevant to the user's message:\n{$lines}";
        } catch (Throwable $e) {
            Log::debug('Memory auto-recall failed', ['error' => $e->getMessage()]);

            return '';
        }
    }
}
