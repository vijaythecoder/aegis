<?php

use App\Services\CostEstimator;
use App\Services\ModelPricingService;

function makeCostEstimator(array $ratesMap = []): CostEstimator
{
    $pricingService = Mockery::mock(ModelPricingService::class);

    $pricingService->shouldReceive('getRates')
        ->andReturnUsing(function (string $provider, string $model) use ($ratesMap): ?array {
            return $ratesMap["{$provider}/{$model}"] ?? null;
        });

    return new CostEstimator($pricingService);
}

test('estimates cost for anthropic claude sonnet', function () {
    $estimator = makeCostEstimator([
        'anthropic/claude-sonnet-4-20250514' => [
            'input' => 3.00,
            'output' => 15.00,
            'cache_read' => 0.30,
            'cache_write' => 3.75,
        ],
    ]);

    $result = $estimator->estimate(
        provider: 'anthropic',
        model: 'claude-sonnet-4-20250514',
        promptTokens: 1000,
        completionTokens: 500,
    );

    expect($result['estimated_cost'])->toBeGreaterThan(0)
        ->and($result['currency'])->toBe('USD');

    $expectedCost = (1000 / 1_000_000) * 3.00 + (500 / 1_000_000) * 15.00;
    expect($result['estimated_cost'])->toBe(round($expectedCost, 6));
});

test('estimates cost with cache tokens', function () {
    $estimator = makeCostEstimator([
        'anthropic/claude-sonnet-4-20250514' => [
            'input' => 3.00,
            'output' => 15.00,
            'cache_read' => 0.30,
            'cache_write' => 3.75,
        ],
    ]);

    $result = $estimator->estimate(
        provider: 'anthropic',
        model: 'claude-sonnet-4-20250514',
        promptTokens: 1000,
        completionTokens: 500,
        cacheReadTokens: 200,
        cacheWriteTokens: 100,
    );

    $expectedCost = (1000 / 1_000_000) * 3.00
        + (500 / 1_000_000) * 15.00
        + (200 / 1_000_000) * 0.30
        + (100 / 1_000_000) * 3.75;

    expect($result['estimated_cost'])->toBe(round($expectedCost, 6));
});

test('estimates cost with reasoning tokens using separate reasoning rate', function () {
    $estimator = makeCostEstimator([
        'openai/o3' => [
            'input' => 10.00,
            'output' => 40.00,
            'reasoning' => 40.00,
        ],
    ]);

    $result = $estimator->estimate(
        provider: 'openai',
        model: 'o3',
        promptTokens: 1000,
        completionTokens: 500,
        reasoningTokens: 300,
    );

    $expectedCost = (1000 / 1_000_000) * 10.00
        + (500 / 1_000_000) * 40.00
        + (300 / 1_000_000) * 40.00;
    expect($result['estimated_cost'])->toBe(round($expectedCost, 6));
});

test('reasoning tokens fallback to output rate when no reasoning rate', function () {
    $estimator = makeCostEstimator([
        'openai/gpt-4o' => [
            'input' => 2.50,
            'output' => 10.00,
        ],
    ]);

    $result = $estimator->estimate(
        provider: 'openai',
        model: 'gpt-4o',
        promptTokens: 1000,
        completionTokens: 500,
        reasoningTokens: 300,
    );

    $expectedCost = (1000 / 1_000_000) * 2.50
        + (500 / 1_000_000) * 10.00
        + (300 / 1_000_000) * 10.00;
    expect($result['estimated_cost'])->toBe(round($expectedCost, 6));
});

test('returns zero cost for ollama local provider', function () {
    $estimator = makeCostEstimator();

    $result = $estimator->estimate(
        provider: 'ollama',
        model: 'llama3.2',
        promptTokens: 5000,
        completionTokens: 2000,
    );

    expect($result['estimated_cost'])->toBe(0.0);
});

test('returns zero cost for unknown model', function () {
    $estimator = makeCostEstimator();

    $result = $estimator->estimate(
        provider: 'anthropic',
        model: 'nonexistent-model',
        promptTokens: 1000,
        completionTokens: 500,
    );

    expect($result['estimated_cost'])->toBe(0.0);
});

test('identifies local providers', function () {
    $estimator = makeCostEstimator();

    expect($estimator->isLocalProvider('ollama'))->toBeTrue()
        ->and($estimator->isLocalProvider('anthropic'))->toBeFalse()
        ->and($estimator->isLocalProvider('openai'))->toBeFalse();
});

test('returns rates for known models', function () {
    $estimator = makeCostEstimator([
        'anthropic/claude-sonnet-4-20250514' => [
            'input' => 3.00,
            'output' => 15.00,
            'cache_read' => 0.30,
            'cache_write' => 3.75,
        ],
    ]);

    $rates = $estimator->ratesFor('anthropic', 'claude-sonnet-4-20250514');

    expect($rates)->toBeArray()
        ->and($rates['input'])->toBe(3.00)
        ->and($rates['output'])->toBe(15.00);
});

test('returns null rates for unknown models', function () {
    $estimator = makeCostEstimator();

    expect($estimator->ratesFor('anthropic', 'unknown'))->toBeNull()
        ->and($estimator->ratesFor('ollama', 'anything'))->toBeNull();
});
