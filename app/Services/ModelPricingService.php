<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class ModelPricingService
{
    private const CACHE_KEY = 'models_dev_pricing';

    private const CACHE_TTL_HOURS = 24;

    /**
     * @return array{input: float, output: float, cache_read?: float, cache_write?: float, reasoning?: float}|null
     */
    public function getRates(string $provider, string $model): ?array
    {
        $pricing = $this->getCachedPricing();

        if ($pricing === null) {
            return null;
        }

        $providerKey = $this->normalizeProvider($provider);
        $cost = $pricing[$providerKey][$model] ?? null;

        if ($cost !== null) {
            return $cost;
        }

        foreach ($pricing[$providerKey] ?? [] as $modelId => $rates) {
            if (str_contains($modelId, $model) || str_contains($model, $modelId)) {
                return $rates;
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

        $pricing = $this->transformApiData($data);
        Cache::put(self::CACHE_KEY, $pricing, now()->addHours(self::CACHE_TTL_HOURS));

        return true;
    }

    public function isStale(): bool
    {
        return ! Cache::has(self::CACHE_KEY);
    }

    public function getProviderModels(string $provider): array
    {
        $pricing = $this->getCachedPricing();

        if ($pricing === null) {
            return [];
        }

        $providerKey = $this->normalizeProvider($provider);

        return array_keys($pricing[$providerKey] ?? []);
    }

    private function getCachedPricing(): ?array
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
            $url = config('aegis.pricing.api_url', 'https://models.dev/api.json');

            $response = Http::timeout(15)
                ->retry(2, 1000)
                ->get($url);

            if (! $response->successful()) {
                Log::warning('ModelPricingService: API returned non-200', ['status' => $response->status()]);

                return null;
            }

            return $response->json();
        } catch (Throwable $e) {
            Log::warning('ModelPricingService: failed to fetch pricing', ['error' => $e->getMessage()]);

            return null;
        }
    }

    /**
     * @return array<string, array<string, array{input: float, output: float, cache_read?: float, cache_write?: float, reasoning?: float}>>
     */
    private function transformApiData(array $data): array
    {
        $pricing = [];

        foreach ($data as $providerId => $providerData) {
            if (! is_array($providerData) || ! isset($providerData['models'])) {
                continue;
            }

            foreach ($providerData['models'] as $modelId => $modelData) {
                if (! is_array($modelData) || ! isset($modelData['cost'])) {
                    continue;
                }

                $cost = $modelData['cost'];

                $rates = [];

                if (isset($cost['input'])) {
                    $rates['input'] = (float) $cost['input'];
                }
                if (isset($cost['output'])) {
                    $rates['output'] = (float) $cost['output'];
                }
                if (isset($cost['cache_read'])) {
                    $rates['cache_read'] = (float) $cost['cache_read'];
                }
                if (isset($cost['cache_write'])) {
                    $rates['cache_write'] = (float) $cost['cache_write'];
                }
                if (isset($cost['reasoning'])) {
                    $rates['reasoning'] = (float) $cost['reasoning'];
                }

                if (isset($rates['input'], $rates['output'])) {
                    $pricing[$providerId][$modelId] = $rates;
                }
            }
        }

        return $pricing;
    }

    private function normalizeProvider(string $provider): string
    {
        return match ($provider) {
            'gemini' => 'google',
            default => $provider,
        };
    }
}
