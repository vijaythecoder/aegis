<?php

use App\Services\ModelPricingService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function fakeModelsDevApi(): array
{
    $apiData = [
        'anthropic' => [
            'id' => 'anthropic',
            'name' => 'Anthropic',
            'models' => [
                'claude-sonnet-4-20250514' => [
                    'cost' => [
                        'input' => 3.00,
                        'output' => 15.00,
                        'cache_read' => 0.30,
                        'cache_write' => 3.75,
                    ],
                ],
                'claude-haiku-3-20240307' => [
                    'cost' => [
                        'input' => 0.25,
                        'output' => 1.25,
                    ],
                ],
            ],
        ],
        'openai' => [
            'id' => 'openai',
            'name' => 'OpenAI',
            'models' => [
                'gpt-4o' => [
                    'cost' => [
                        'input' => 2.50,
                        'output' => 10.00,
                    ],
                ],
                'o3' => [
                    'cost' => [
                        'input' => 10.00,
                        'output' => 40.00,
                        'reasoning' => 40.00,
                    ],
                ],
            ],
        ],
        'google' => [
            'id' => 'google',
            'name' => 'Google',
            'models' => [
                'gemini-2.5-pro' => [
                    'cost' => [
                        'input' => 1.25,
                        'output' => 10.00,
                    ],
                ],
            ],
        ],
    ];

    Http::fake([
        'models.dev/api.json' => Http::response($apiData, 200),
    ]);

    return $apiData;
}

beforeEach(function () {
    Cache::forget('models_dev_pricing');
});

test('fetches and caches pricing from models.dev API', function () {
    fakeModelsDevApi();

    $service = new ModelPricingService;

    $rates = $service->getRates('anthropic', 'claude-sonnet-4-20250514');

    expect($rates)->toBeArray()
        ->and($rates['input'])->toBe(3.00)
        ->and($rates['output'])->toBe(15.00)
        ->and($rates['cache_read'])->toBe(0.30)
        ->and($rates['cache_write'])->toBe(3.75);

    Http::assertSentCount(1);
});

test('returns cached pricing without re-fetching', function () {
    fakeModelsDevApi();

    $service = new ModelPricingService;

    $service->getRates('anthropic', 'claude-sonnet-4-20250514');
    $service->getRates('openai', 'gpt-4o');

    Http::assertSentCount(1);
});

test('returns rates for openai models', function () {
    fakeModelsDevApi();

    $service = new ModelPricingService;

    $rates = $service->getRates('openai', 'gpt-4o');

    expect($rates['input'])->toBe(2.50)
        ->and($rates['output'])->toBe(10.00);
});

test('returns reasoning rate when available', function () {
    fakeModelsDevApi();

    $service = new ModelPricingService;

    $rates = $service->getRates('openai', 'o3');

    expect($rates['reasoning'])->toBe(40.00);
});

test('normalizes gemini provider to google', function () {
    fakeModelsDevApi();

    $service = new ModelPricingService;

    $rates = $service->getRates('gemini', 'gemini-2.5-pro');

    expect($rates)->toBeArray()
        ->and($rates['input'])->toBe(1.25)
        ->and($rates['output'])->toBe(10.00);
});

test('returns null for unknown provider', function () {
    fakeModelsDevApi();

    $service = new ModelPricingService;

    expect($service->getRates('unknownprovider', 'some-model'))->toBeNull();
});

test('returns null for unknown model', function () {
    fakeModelsDevApi();

    $service = new ModelPricingService;

    expect($service->getRates('anthropic', 'nonexistent-model-xyz'))->toBeNull();
});

test('uses fuzzy matching for partial model IDs', function () {
    fakeModelsDevApi();

    $service = new ModelPricingService;

    $rates = $service->getRates('anthropic', 'claude-sonnet-4');

    expect($rates)->toBeArray()
        ->and($rates['input'])->toBe(3.00);
});

test('refresh returns true on success', function () {
    fakeModelsDevApi();

    $service = new ModelPricingService;

    expect($service->refresh())->toBeTrue();
});

test('refresh returns false on API failure', function () {
    Http::fake([
        'models.dev/api.json' => Http::response('Server Error', 500),
    ]);

    $service = new ModelPricingService;

    expect($service->refresh())->toBeFalse();
});

test('isStale returns true when cache is empty', function () {
    $service = new ModelPricingService;

    expect($service->isStale())->toBeTrue();
});

test('isStale returns false after refresh', function () {
    fakeModelsDevApi();

    $service = new ModelPricingService;
    $service->refresh();

    expect($service->isStale())->toBeFalse();
});

test('getProviderModels returns model list', function () {
    fakeModelsDevApi();

    $service = new ModelPricingService;

    $models = $service->getProviderModels('anthropic');

    expect($models)->toContain('claude-sonnet-4-20250514')
        ->and($models)->toContain('claude-haiku-3-20240307');
});

test('getProviderModels returns empty array for unknown provider', function () {
    fakeModelsDevApi();

    $service = new ModelPricingService;

    expect($service->getProviderModels('unknownprovider'))->toBeEmpty();
});

test('handles API timeout gracefully', function () {
    Http::fake([
        'models.dev/api.json' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection timed out'),
    ]);

    $service = new ModelPricingService;

    expect($service->getRates('anthropic', 'claude-sonnet-4-20250514'))->toBeNull();
});
