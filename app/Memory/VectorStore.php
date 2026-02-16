<?php

namespace App\Memory;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class VectorStore
{
    /**
     * @param  array<float>  $embedding
     * @param  array{source_type: string, source_id: int, content_preview: string, conversation_id?: int}  $metadata
     */
    public function store(array $embedding, array $metadata): int
    {
        return DB::table('vector_embeddings')->insertGetId([
            'source_type' => $metadata['source_type'],
            'source_id' => $metadata['source_id'],
            'content_preview' => $metadata['content_preview'],
            'conversation_id' => $metadata['conversation_id'] ?? null,
            'embedding' => $this->encode($embedding),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * @param  array<float>  $query
     * @return Collection<int, array{id: int, source_type: string, source_id: int, content_preview: string, score: float}>
     */
    public function search(array $query, int $limit = 5): Collection
    {
        $rows = DB::table('vector_embeddings')
            ->select(['id', 'source_type', 'source_id', 'content_preview', 'conversation_id', 'embedding', 'created_at'])
            ->get();

        if ($rows->isEmpty()) {
            return collect();
        }

        return $rows
            ->map(function ($row) use ($query) {
                $stored = $this->decode($row->embedding);

                return [
                    'id' => $row->id,
                    'source_type' => $row->source_type,
                    'source_id' => $row->source_id,
                    'content_preview' => $row->content_preview,
                    'conversation_id' => $row->conversation_id,
                    'created_at' => $row->created_at,
                    'score' => $this->cosineSimilarity($query, $stored),
                ];
            })
            ->sortByDesc('score')
            ->take($limit)
            ->values();
    }

    public function delete(int $id): void
    {
        DB::table('vector_embeddings')->where('id', $id)->delete();
    }

    public function deleteBySource(string $sourceType, int $sourceId): void
    {
        DB::table('vector_embeddings')
            ->where('source_type', $sourceType)
            ->where('source_id', $sourceId)
            ->delete();
    }

    /**
     * @param  array<float>  $a
     * @param  array<float>  $b
     */
    protected function cosineSimilarity(array $a, array $b): float
    {
        $dotProduct = 0.0;
        $magnitudeA = 0.0;
        $magnitudeB = 0.0;

        $count = min(count($a), count($b));

        for ($i = 0; $i < $count; $i++) {
            $dotProduct += $a[$i] * $b[$i];
            $magnitudeA += $a[$i] * $a[$i];
            $magnitudeB += $b[$i] * $b[$i];
        }

        $magnitudeA = sqrt($magnitudeA);
        $magnitudeB = sqrt($magnitudeB);

        if ($magnitudeA == 0.0 || $magnitudeB == 0.0) {
            return 0.0;
        }

        return $dotProduct / ($magnitudeA * $magnitudeB);
    }

    /**
     * @param  array<float>  $embedding
     */
    protected function encode(array $embedding): string
    {
        return pack('f*', ...$embedding);
    }

    /**
     * @return array<float>
     */
    protected function decode(string $binary): array
    {
        return array_values(unpack('f*', $binary));
    }
}
