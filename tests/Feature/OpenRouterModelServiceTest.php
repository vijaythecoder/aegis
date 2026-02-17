<?php

use App\Services\OpenRouterModelService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

function fakeOpenRouterApi(): array
{
    $apiData = [
        [
            'id' => 'anthropic/claude-sonnet-4',
            'name' => 'Anthropic: Claude Sonnet 4',
            'context_length' => 200000,
            'pricing' => ['prompt' => '0.000003', 'completion' => '0.000015'],
            'architecture' => ['input_modalities' => ['text', 'image'], 'output_modalities' => ['text']],
            'supported_parameters' => ['max_tokens', 'temperature', 'tools', 'structured_outputs'],
        ],
        [
            'id' => 'openai/gpt-4o',
            'name' => 'OpenAI: GPT-4o',
            'context_length' => 128000,
            'pricing' => ['prompt' => '0.0000025', 'completion' => '0.00001'],
            'architecture' => ['input_modalities' => ['text', 'image'], 'output_modalities' => ['text']],
            'supported_parameters' => ['max_tokens', 'temperature', 'tools'],
        ],
        [
            'id' => 'deepseek/deepseek-chat',
            'name' => 'DeepSeek: V3',
            'context_length' => 64000,
            'pricing' => ['prompt' => '0.00000055', 'completion' => '0.00000219'],
            'architecture' => ['input_modalities' => ['text'], 'output_modalities' => ['text']],
            'supported_parameters' => ['max_tokens', 'temperature', 'tools'],
        ],
    ];

    Http::fake([
        'openrouter.ai/api/v1/models' => Http::response(['data' => $apiData], 200),
    ]);

    return $apiData;
}

beforeEach(function () {
    Cache::forget('openrouter_available_models');
});

test('fetches and caches models from OpenRouter API', function () {
    fakeOpenRouterApi();

    $service = new OpenRouterModelService;

    $models = $service->getModels();

    expect($models)->toHaveCount(3)
        ->and($models[0]['id'])->toBe('anthropic/claude-sonnet-4')
        ->and($models[0]['context_length'])->toBe(200000)
        ->and($models[0]['tools'])->toBeTrue()
        ->and($models[0]['vision'])->toBeTrue()
        ->and($models[0]['structured_output'])->toBeTrue();

    Http::assertSentCount(1);
});

test('returns cached models without re-fetching', function () {
    fakeOpenRouterApi();

    $service = new OpenRouterModelService;

    $service->getModels();
    $service->getModels();

    Http::assertSentCount(1);
});

test('getModelIds returns just ID strings', function () {
    fakeOpenRouterApi();

    $service = new OpenRouterModelService;

    $ids = $service->getModelIds();

    expect($ids)->toContain('anthropic/claude-sonnet-4')
        ->and($ids)->toContain('openai/gpt-4o')
        ->and($ids)->toContain('deepseek/deepseek-chat');
});

test('getModelDetails returns details for specific model', function () {
    fakeOpenRouterApi();

    $service = new OpenRouterModelService;

    $details = $service->getModelDetails('openai/gpt-4o');

    expect($details)->toBeArray()
        ->and($details['name'])->toBe('OpenAI: GPT-4o')
        ->and($details['context_length'])->toBe(128000)
        ->and($details['vision'])->toBeTrue();
});

test('getModelDetails returns null for unknown model', function () {
    fakeOpenRouterApi();

    $service = new OpenRouterModelService;

    expect($service->getModelDetails('nonexistent/model'))->toBeNull();
});

test('converts pricing from per-token to per-million-tokens', function () {
    fakeOpenRouterApi();

    $service = new OpenRouterModelService;

    $details = $service->getModelDetails('anthropic/claude-sonnet-4');

    expect($details['pricing']['input'])->toBe(3.0)
        ->and($details['pricing']['output'])->toBe(15.0);
});

test('detects vision support from input modalities', function () {
    fakeOpenRouterApi();

    $service = new OpenRouterModelService;

    $withVision = $service->getModelDetails('anthropic/claude-sonnet-4');
    $withoutVision = $service->getModelDetails('deepseek/deepseek-chat');

    expect($withVision['vision'])->toBeTrue()
        ->and($withoutVision['vision'])->toBeFalse();
});

test('refresh returns true on success', function () {
    fakeOpenRouterApi();

    $service = new OpenRouterModelService;

    expect($service->refresh())->toBeTrue();
});

test('refresh returns false on API failure', function () {
    Http::fake([
        'openrouter.ai/api/v1/models' => Http::response('Server Error', 500),
    ]);

    $service = new OpenRouterModelService;

    expect($service->refresh())->toBeFalse();
});

test('isStale returns true when cache is empty', function () {
    $service = new OpenRouterModelService;

    expect($service->isStale())->toBeTrue();
});

test('isStale returns false after refresh', function () {
    fakeOpenRouterApi();

    $service = new OpenRouterModelService;
    $service->refresh();

    expect($service->isStale())->toBeFalse();
});

test('returns empty array on API failure', function () {
    Http::fake([
        'openrouter.ai/api/v1/models' => Http::response('Server Error', 500),
    ]);

    $service = new OpenRouterModelService;

    expect($service->getModels())->toBeEmpty()
        ->and($service->getModelIds())->toBeEmpty();
});

test('handles API timeout gracefully', function () {
    Http::fake([
        'openrouter.ai/api/v1/models' => fn () => throw new \Illuminate\Http\Client\ConnectionException('Connection timed out'),
    ]);

    $service = new OpenRouterModelService;

    expect($service->getModels())->toBeEmpty();
});
