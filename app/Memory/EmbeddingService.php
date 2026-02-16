<?php

namespace App\Memory;

use Illuminate\Support\Facades\Log;
use Laravel\Ai\Embeddings;
use Throwable;

class EmbeddingService
{
    /**
     * Generate an embedding vector for a single text.
     *
     * @return array<float>|null
     */
    public function embed(string $text): ?array
    {
        if ($this->isDisabled()) {
            return null;
        }

        try {
            $response = Embeddings::for([$text])
                ->dimensions($this->dimensions())
                ->generate($this->provider(), $this->model());

            return $response->first();
        } catch (Throwable $e) {
            Log::warning('Embedding generation failed', [
                'provider' => config('aegis.memory.embedding_provider'),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Generate embeddings for multiple texts in a single batch.
     *
     * @param  array<string>  $texts
     * @return array<int, array<float>>
     */
    public function embedBatch(array $texts): array
    {
        if ($this->isDisabled() || empty($texts)) {
            return [];
        }

        try {
            $response = Embeddings::for($texts)
                ->dimensions($this->dimensions())
                ->generate($this->provider(), $this->model());

            return $response->embeddings;
        } catch (Throwable $e) {
            Log::warning('Batch embedding generation failed', [
                'provider' => config('aegis.memory.embedding_provider'),
                'count' => count($texts),
                'error' => $e->getMessage(),
            ]);

            return [];
        }
    }

    protected function isDisabled(): bool
    {
        return config('aegis.memory.embedding_provider') === 'disabled';
    }

    protected function provider(): string
    {
        return config('aegis.memory.embedding_provider', 'ollama');
    }

    protected function model(): ?string
    {
        return config('aegis.memory.embedding_model');
    }

    protected function dimensions(): int
    {
        return (int) config('aegis.memory.embedding_dimensions', 768);
    }
}
