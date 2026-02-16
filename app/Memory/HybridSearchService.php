<?php

namespace App\Memory;

use Illuminate\Support\Collection;
use Laravel\Ai\Reranking;
use Throwable;

class HybridSearchService
{
    public function __construct(
        protected VectorStore $vectorStore,
        protected MemoryService $memoryService,
    ) {}

    /**
     * @param  array<float>|null  $queryEmbedding
     * @return Collection<int, array{source_type: string, source_id: int, content_preview: string, score: float}>
     */
    public function search(string $query, ?array $queryEmbedding = null, int $limit = 10): Collection
    {
        $alpha = (float) config('aegis.memory.hybrid_search_alpha', 0.7);

        $vectorResults = $this->vectorSearch($queryEmbedding, $limit);
        $ftsResults = $this->ftsSearch($query, $limit);

        if ($vectorResults->isEmpty() && $ftsResults->isEmpty()) {
            return collect();
        }

        if ($vectorResults->isEmpty()) {
            $results = $ftsResults->take($limit);
        } elseif ($ftsResults->isEmpty()) {
            $results = $vectorResults->take($limit);
        } else {
            $results = $this->fuseResults($vectorResults, $ftsResults, $alpha)->take($limit);
        }

        if (config('aegis.memory.reranking_enabled', false) && $results->count() > 1) {
            return $this->rerank($results, $query, $limit);
        }

        return $results;
    }

    protected function vectorSearch(?array $queryEmbedding, int $limit): Collection
    {
        if ($queryEmbedding === null) {
            return collect();
        }

        return $this->vectorStore->search($queryEmbedding, $limit);
    }

    protected function ftsSearch(string $query, int $limit): Collection
    {
        $query = trim($query);

        if ($query === '') {
            return collect();
        }

        $memories = $this->memoryService->search($query);

        return $memories->take($limit)->values()->map(fn ($memory, $index) => [
            'source_type' => 'memory',
            'source_id' => $memory->id,
            'content_preview' => $memory->value,
            'score' => 1.0 - ($index * 0.1),
        ]);
    }

    protected function rerank(Collection $results, string $query, int $limit): Collection
    {
        try {
            $documents = $results->pluck('content_preview')->values()->all();

            $reranked = Reranking::of($documents)
                ->limit($limit)
                ->rerank($query);

            $indexed = $results->values();

            return collect($reranked->results)->map(function ($rankedDoc) use ($indexed) {
                $original = $indexed[$rankedDoc->index];

                return [
                    ...$original,
                    'score' => $rankedDoc->score,
                ];
            })->values();
        } catch (Throwable) {
            return $results;
        }
    }

    protected function fuseResults(Collection $vectorResults, Collection $ftsResults, float $alpha): Collection
    {
        $merged = collect();

        foreach ($vectorResults as $result) {
            $key = $result['source_type'].'_'.$result['source_id'];
            $merged[$key] = [
                ...$result,
                'score' => $alpha * $result['score'],
            ];
        }

        foreach ($ftsResults as $result) {
            $key = $result['source_type'].'_'.$result['source_id'];

            if ($merged->has($key)) {
                $merged[$key] = [
                    ...$merged[$key],
                    'score' => $merged[$key]['score'] + (1 - $alpha) * $result['score'],
                ];
            } else {
                $merged[$key] = [
                    ...$result,
                    'score' => (1 - $alpha) * $result['score'],
                ];
            }
        }

        return $merged->sortByDesc('score')->values();
    }
}
