<?php

namespace App\Services;

class CostEstimator
{
    private const TOKENS_PER_MILLION = 1_000_000;

    public function __construct(private readonly ModelPricingService $pricingService) {}

    /**
     * @return array{estimated_cost: float, currency: string}
     */
    public function estimate(
        string $provider,
        string $model,
        int $promptTokens,
        int $completionTokens,
        int $cacheReadTokens = 0,
        int $cacheWriteTokens = 0,
        int $reasoningTokens = 0,
    ): array {
        $rates = $this->ratesFor($provider, $model);

        if ($rates === null) {
            return ['estimated_cost' => 0.0, 'currency' => 'USD'];
        }

        $inputRate = $rates['input'] ?? 0;
        $outputRate = $rates['output'] ?? 0;
        $reasoningRate = $rates['reasoning'] ?? $outputRate;
        $cacheReadRate = $rates['cache_read'] ?? 0;
        $cacheWriteRate = $rates['cache_write'] ?? 0;

        $cost = 0.0;
        $cost += ($promptTokens / self::TOKENS_PER_MILLION) * $inputRate;
        $cost += ($completionTokens / self::TOKENS_PER_MILLION) * $outputRate;
        $cost += ($reasoningTokens / self::TOKENS_PER_MILLION) * $reasoningRate;
        $cost += ($cacheReadTokens / self::TOKENS_PER_MILLION) * $cacheReadRate;
        $cost += ($cacheWriteTokens / self::TOKENS_PER_MILLION) * $cacheWriteRate;

        return ['estimated_cost' => round($cost, 6), 'currency' => 'USD'];
    }

    public function isLocalProvider(string $provider): bool
    {
        return $provider === 'ollama';
    }

    /**
     * @return array{input: float, output: float, cache_read?: float, cache_write?: float, reasoning?: float}|null
     */
    public function ratesFor(string $provider, string $model): ?array
    {
        if ($this->isLocalProvider($provider)) {
            return null;
        }

        return $this->pricingService->getRates($provider, $model);
    }
}
