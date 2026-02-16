<?php

namespace App\Rag;

use App\Memory\EmbeddingService;
use App\Memory\VectorStore;
use App\Models\Document;
use App\Models\DocumentChunk;
use Illuminate\Support\Collection;

class RetrievalService
{
    public function __construct(
        private EmbeddingService $embedder,
        private VectorStore $vectorStore,
    ) {}

    /** @return array<int, array{content: string, score: float, document_name: string, file_type: string, chunk_index: int, start_line: int|null, end_line: int|null}> */
    public function retrieve(string $query, int $limit = 0, ?string $documentPath = null): array
    {
        if ($limit <= 0) {
            $limit = (int) config('aegis.rag.max_retrieval_results', 10);
        }

        $queryEmbedding = $this->embedder->embed($query);

        if ($queryEmbedding === null) {
            return $this->fallbackTextSearch($query, $limit, $documentPath);
        }

        $vectorResults = $this->vectorStore->search($queryEmbedding, $limit * 2);

        $chunkResults = $vectorResults->filter(
            fn ($r) => $r['source_type'] === 'document_chunk'
        );

        if ($chunkResults->isEmpty()) {
            return $this->fallbackTextSearch($query, $limit, $documentPath);
        }

        return $this->resolveChunks($chunkResults, $limit, $documentPath);
    }

    /** @return array<int, array{content: string, score: float, document_name: string, file_type: string, chunk_index: int, start_line: int|null, end_line: int|null}> */
    private function resolveChunks(Collection $vectorResults, int $limit, ?string $documentPath): array
    {
        $embeddingIds = $vectorResults->pluck('id')->toArray();
        $scoreMap = $vectorResults->keyBy('id')->map(fn ($r) => $r['score'])->toArray();

        $query = DocumentChunk::whereIn('embedding_id', $embeddingIds);

        if ($documentPath) {
            $docId = Document::where('path', $documentPath)->value('id');
            if (! $docId) {
                return [];
            }
            $query->where('document_id', $docId);
        }

        $chunks = $query->get();

        $documentIds = $chunks->pluck('document_id')->unique()->toArray();
        $documents = Document::whereIn('id', $documentIds)->get()->keyBy('id');

        $results = $chunks->map(function ($chunk) use ($scoreMap, $documents) {
            $doc = $documents->get($chunk->document_id);

            return [
                'content' => $chunk->content,
                'score' => $scoreMap[$chunk->embedding_id] ?? 0.0,
                'document_name' => $doc?->name ?? 'unknown',
                'file_type' => $doc?->file_type ?? 'unknown',
                'chunk_index' => $chunk->chunk_index,
                'start_line' => $chunk->start_line,
                'end_line' => $chunk->end_line,
            ];
        });

        return $results
            ->sortByDesc('score')
            ->take($limit)
            ->values()
            ->toArray();
    }

    /** @return array<int, array{content: string, score: float, document_name: string, file_type: string, chunk_index: int, start_line: int|null, end_line: int|null}> */
    private function fallbackTextSearch(string $query, int $limit, ?string $documentPath): array
    {
        $keywords = $this->extractKeywords($query);

        if (empty($keywords)) {
            return [];
        }

        $matchingDocIds = Document::query()
            ->where(function ($q) use ($keywords): void {
                foreach ($keywords as $keyword) {
                    $q->orWhere('name', 'like', '%'.$keyword.'%')
                        ->orWhere('path', 'like', '%'.$keyword.'%');
                }
            })
            ->pluck('id')
            ->toArray();

        $chunksQuery = DocumentChunk::query()
            ->where(function ($q) use ($keywords, $matchingDocIds): void {
                foreach ($keywords as $keyword) {
                    $q->orWhere('content', 'like', '%'.$keyword.'%');
                }

                if ($matchingDocIds !== []) {
                    $q->orWhereIn('document_id', $matchingDocIds);
                }
            });

        if ($documentPath) {
            $docId = Document::where('path', $documentPath)->value('id');
            if (! $docId) {
                return [];
            }
            $chunksQuery->where('document_id', $docId);
        }

        $chunks = $chunksQuery->limit($limit * 3)->get();

        $documentIds = $chunks->pluck('document_id')->unique()->toArray();
        $documents = Document::whereIn('id', $documentIds)->get()->keyBy('id');

        $scored = $chunks->map(function ($chunk) use ($keywords, $documents) {
            $hits = 0;
            $lower = mb_strtolower($chunk->content);
            $docName = mb_strtolower($documents->get($chunk->document_id)?->name ?? '');

            foreach ($keywords as $keyword) {
                if (str_contains($lower, $keyword)) {
                    $hits++;
                }
                if (str_contains($docName, $keyword)) {
                    $hits++;
                }
            }

            return ['chunk' => $chunk, 'hits' => $hits];
        })
            ->filter(fn (array $item): bool => $item['hits'] > 0)
            ->sortByDesc('hits')
            ->take($limit);

        return $scored->values()->map(function ($item) use ($documents, $keywords) {
            $chunk = $item['chunk'];
            $doc = $documents->get($chunk->document_id);

            return [
                'content' => $chunk->content,
                'score' => min(1.0, $item['hits'] / max(count($keywords) * 2, 1)),
                'document_name' => $doc?->name ?? 'unknown',
                'file_type' => $doc?->file_type ?? 'unknown',
                'chunk_index' => $chunk->chunk_index,
                'start_line' => $chunk->start_line,
                'end_line' => $chunk->end_line,
            ];
        })->toArray();
    }

    /** @return string[] */
    private function extractKeywords(string $query): array
    {
        $stopWords = [
            'a', 'about', 'an', 'and', 'any', 'are', 'at', 'be', 'but', 'by',
            'can', 'could', 'do', 'does', 'for', 'from', 'had', 'has', 'have',
            'how', 'i', 'if', 'in', 'into', 'is', 'it', 'know', 'me', 'my',
            'no', 'not', 'of', 'on', 'or', 'our', 'some', 'tell', 'that', 'the',
            'their', 'them', 'then', 'there', 'these', 'they', 'this', 'to', 'up',
            'us', 'was', 'we', 'what', 'when', 'where', 'which', 'who', 'will',
            'with', 'would', 'yes', 'you', 'your', 'anything', 'everything',
            'something', 'nothing',
        ];

        $words = preg_split('/\W+/u', mb_strtolower($query));

        return array_values(array_filter(
            $words,
            fn (string $w): bool => mb_strlen($w) >= 2 && ! in_array($w, $stopWords, true),
        ));
    }
}
