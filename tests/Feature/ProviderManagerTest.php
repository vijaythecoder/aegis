<?php

use App\Agent\ModelCapabilities;
use App\Agent\ProviderManager;
use App\Security\ApiKeyManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

it('fails over to next provider when primary provider throws', function () {
    config()->set('aegis.failover_chain', ['openai']);
    Log::spy();

    $manager = app(ProviderManager::class);

    $result = $manager->failover('anthropic', function (string $provider): string {
        if ($provider === 'anthropic') {
            throw new RuntimeException('primary failed');
        }

        return 'ok:'.$provider;
    });

    expect($result)->toBe('ok:openai');

    Log::shouldHaveReceived('warning')
        ->once()
        ->withArgs(fn (string $message, array $context): bool => $message === 'aegis.provider.failover' && ($context['failed_provider'] ?? null) === 'anthropic');
});

it('detects ollama models when service is available', function () {
    Http::fake([
        'http://localhost:11434/api/tags' => Http::response([
            'models' => [
                ['name' => 'llama3.2:latest'],
                ['name' => 'qwen2.5:7b'],
            ],
        ], 200),
    ]);

    $models = app(ProviderManager::class)->detectOllama();

    expect($models)->toBe(['llama3.2:latest', 'qwen2.5:7b']);
});

it('returns empty ollama model list when service is unavailable', function () {
    Http::fake([
        'http://localhost:11434/api/tags' => Http::response([], 500),
    ]);

    $models = app(ProviderManager::class)->detectOllama();

    expect($models)->toBe([]);
});

it('verifies per-model context windows in model capabilities', function () {
    expect((new ModelCapabilities)->contextWindow('anthropic', 'claude-sonnet-4-20250514'))->toBe(200000)
        ->and((new ModelCapabilities)->contextWindow('openai', 'gpt-4o'))->toBe(128000);
});

it('tracks provider rate limits and blocks once threshold is exceeded', function () {
    config()->set('aegis.agent.rate_limit_max_requests', 2);
    config()->set('aegis.failover_chain', []);

    Cache::flush();

    $manager = app(ProviderManager::class);
    $manager->trackRateLimit('anthropic');

    expect($manager->isRateLimited('anthropic'))->toBeFalse();

    $manager->trackRateLimit('anthropic');

    expect($manager->isRateLimited('anthropic'))->toBeTrue()
        ->and(fn () => $manager->failover('anthropic', fn () => 'ok'))->toThrow(RuntimeException::class);
});

it('reports provider availability including keyless local provider', function () {
    $manager = app(ProviderManager::class);
    $keys = app(ApiKeyManager::class);

    $availableBefore = $manager->availableProviders();

    $keys->store('anthropic', 'sk-ant-ABCDEFGHIJKLMNOPQRSTUVWXYZ1234');
    $availableAfter = $manager->availableProviders();

    expect($availableBefore['anthropic']['available'])->toBeFalse()
        ->and($availableBefore['ollama']['available'])->toBeTrue()
        ->and($availableAfter['anthropic']['available'])->toBeTrue();
});
