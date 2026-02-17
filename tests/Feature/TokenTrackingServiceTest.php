<?php

use App\Models\Conversation;
use App\Models\TokenUsage;
use App\Services\CostEstimator;
use App\Services\ModelPricingService;
use App\Services\TokenTrackingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;

uses(RefreshDatabase::class);

function makeTrackingService(array $ratesMap = []): TokenTrackingService
{
    $pricingService = Mockery::mock(ModelPricingService::class);

    $pricingService->shouldReceive('getRates')
        ->andReturnUsing(function (string $provider, string $model) use ($ratesMap): ?array {
            return $ratesMap["{$provider}/{$model}"] ?? null;
        });

    return new TokenTrackingService(new CostEstimator($pricingService));
}

test('records token usage from usage and meta objects', function () {
    $service = makeTrackingService([
        'anthropic/claude-sonnet-4-20250514' => [
            'input' => 3.00,
            'output' => 15.00,
            'cache_read' => 0.30,
            'cache_write' => 3.75,
        ],
    ]);
    $conversation = Conversation::factory()->create();

    $usage = new Usage(
        promptTokens: 1500,
        completionTokens: 800,
        cacheWriteInputTokens: 100,
        cacheReadInputTokens: 200,
        reasoningTokens: 50,
    );

    $meta = new Meta(provider: 'anthropic', model: 'claude-sonnet-4-20250514');

    $record = $service->record(
        usage: $usage,
        meta: $meta,
        agentClass: 'App\\Agent\\AegisAgent',
        conversationId: $conversation->id,
    );

    expect($record)->toBeInstanceOf(TokenUsage::class)
        ->and($record->prompt_tokens)->toBe(1500)
        ->and($record->completion_tokens)->toBe(800)
        ->and($record->cache_read_tokens)->toBe(200)
        ->and($record->cache_write_tokens)->toBe(100)
        ->and($record->reasoning_tokens)->toBe(50)
        ->and($record->total_tokens)->toBe(2300)
        ->and($record->provider)->toBe('anthropic')
        ->and($record->model)->toBe('claude-sonnet-4-20250514')
        ->and($record->agent_class)->toBe('App\\Agent\\AegisAgent')
        ->and($record->conversation_id)->toBe($conversation->id)
        ->and((float) $record->estimated_cost)->toBeGreaterThan(0);
});

test('records zero cost for local provider', function () {
    $service = makeTrackingService();

    $usage = new Usage(promptTokens: 5000, completionTokens: 2000);
    $meta = new Meta(provider: 'ollama', model: 'llama3.2');

    $record = $service->record(usage: $usage, meta: $meta);

    expect((float) $record->estimated_cost)->toBe(0.0)
        ->and($record->total_tokens)->toBe(7000);
});

test('handles null provider and model gracefully', function () {
    $service = makeTrackingService();

    $usage = new Usage(promptTokens: 100, completionTokens: 50);
    $meta = new Meta(provider: null, model: null);

    $record = $service->record(usage: $usage, meta: $meta);

    expect($record->provider)->toBe('unknown')
        ->and($record->model)->toBe('unknown')
        ->and($record->total_tokens)->toBe(150);
});

test('persists record to database', function () {
    $service = makeTrackingService([
        'openai/gpt-4o' => [
            'input' => 2.50,
            'output' => 10.00,
        ],
    ]);

    $usage = new Usage(promptTokens: 500, completionTokens: 200);
    $meta = new Meta(provider: 'openai', model: 'gpt-4o');

    $service->record(usage: $usage, meta: $meta);

    expect(TokenUsage::query()->count())->toBe(1);

    $saved = TokenUsage::query()->first();
    expect($saved->prompt_tokens)->toBe(500)
        ->and($saved->completion_tokens)->toBe(200)
        ->and($saved->provider)->toBe('openai')
        ->and($saved->model)->toBe('gpt-4o');
});
