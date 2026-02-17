<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class OpenRouterModelService
{
    private const CACHE_KEY = 'openrouter_available_models';

    /**
     * @return array<int, array{id: string, name: string, context_length: int, tools: bool, vision: bool, streaming: bool, structured_output: bool, pricing: array{input: float, output: float}}>
     */
    public function getModels(): array
    {
        return $this->getCachedModels() ?? [];
    }

    /**
     * @return list<string>
     */
    public function getModelIds(): array
    {
        return array_column($this->getModels(), 'id');
    }

    /**
     * @return array{id: string, name: string, context_length: int, tools: bool, vision: bool, streaming: bool, structured_output: bool, pricing: array{input: float, output: float}}|null
     */
    public function getModelDetails(string $modelId): ?array
    {
        foreach ($this->getModels() as $model) {
            if ($model['id'] === $modelId) {
                return $model;
            }
        }

        return null;
    }

    public function refresh(): bool
    {
        $data = $this->fetchFromApi();

        if ($data === null) {
            return false;
        }

        $models = $this->transformApiData($data);
        $ttl = max(1, (int) config('aegis.openrouter.cache_ttl_hours', 24));
        Cache::put(self::CACHE_KEY, $models, now()->addHours($ttl));

        return true;
    }

    public function isStale(): bool
    {
        return ! Cache::has(self::CACHE_KEY);
    }

    private function getCachedModels(): ?array
    {
        $cached = Cache::get(self::CACHE_KEY);

        if ($cached !== null) {
            return $cached;
        }

        if ($this->refresh()) {
            return Cache::get(self::CACHE_KEY);
        }

        return null;
    }

    private function fetchFromApi(): ?array
    {
        try {
            $url = config('aegis.openrouter.models_api_url', 'https://openrouter.ai/api/v1/models');

            $response = Http::timeout(15)
                ->retry(2, 1000)
                ->get($url);

            if (! $response->successful()) {
                Log::warning('OpenRouterModelService: API returned non-200', ['status' => $response->status()]);

                return null;
            }

            return $response->json('data', []);
        } catch (Throwable $e) {
            Log::warning('OpenRouterModelService: failed to fetch models', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @return array<int, array{id: string, name: string, context_length: int, tools: bool, vision: bool, streaming: bool, structured_output: bool, pricing: array{input: float, output: float}}>
     */
    private function transformApiData(array $data): array
    {
        $models = [];

        foreach ($data as $item) {
            if (! is_array($item) || ! isset($item['id'], $item['name'])) {
                continue;
            }

            $params = $item['supported_parameters'] ?? [];
            $inputModalities = $item['architecture']['input_modalities'] ?? [];
            $pricing = $item['pricing'] ?? [];

            $models[] = [
                'id' => (string) $item['id'],
                'name' => (string) $item['name'],
                'context_length' => (int) ($item['context_length'] ?? 0),
                'tools' => in_array('tools', $params, true),
                'vision' => in_array('image', $inputModalities, true),
                'streaming' => true,
                'structured_output' => in_array('structured_outputs', $params, true),
                'pricing' => [
                    'input' => (float) ($pricing['prompt'] ?? 0) * 1_000_000,
                    'output' => (float) ($pricing['completion'] ?? 0) * 1_000_000,
                ],
            ];
        }

        return $models;
    }
}
