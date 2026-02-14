<?php

use App\Agent\AgentOrchestrator;
use App\Agent\ContextManager;
use App\Agent\ModelCapabilities;
use App\Agent\ProviderManager;
use App\Agent\SystemPromptBuilder;
use App\Agent\ToolResult;
use App\Models\Conversation;
use App\Security\ApiKeyManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;

uses(RefreshDatabase::class);

it('switches llm provider and model per request override', function () {
    $conversation = Conversation::factory()->create();

    $fake = Prism::fake([
        TextResponseFake::make()->withText('anthropic response'),
        TextResponseFake::make()->withText('openai response'),
    ]);

    $orchestrator = app(AgentOrchestrator::class);

    $first = $orchestrator->respond('hello', $conversation->id, 'anthropic', 'claude-sonnet-4-20250514');
    $second = $orchestrator->respond('hello again', $conversation->id, 'openai', 'gpt-4o');

    expect($first)->toBe('anthropic response')
        ->and($second)->toBe('openai response');

    $fake->assertRequest(function (array $requests): void {
        expect($requests)->toHaveCount(2)
            ->and($requests[0]->provider())->toBe('anthropic')
            ->and($requests[0]->model())->toBe('claude-sonnet-4-20250514')
            ->and($requests[1]->provider())->toBe('openai')
            ->and($requests[1]->model())->toBe('gpt-4o');
    });
});

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

it('uses per-model context windows in context manager', function () {
    config()->set('aegis.agent.context_window', 1000);

    $manager = new ContextManager(null, new ModelCapabilities);
    $messages = collect(range(1, 6))->map(fn (int $i): array => [
        'role' => $i % 2 === 0 ? 'assistant' : 'user',
        'content' => str_repeat('x', 1200),
    ])->all();

    $fallback = $manager->truncateMessages('system', $messages);
    $claude = $manager->truncateMessages('system', $messages, null, null, null, 'anthropic', 'claude-sonnet-4-20250514');
    $gpt4o = $manager->truncateMessages('system', $messages, null, null, null, 'openai', 'gpt-4o');

    expect(count($fallback))->toBeLessThan(count($claude))
        ->and(count($fallback))->toBeLessThan(count($gpt4o))
        ->and((new ModelCapabilities)->contextWindow('anthropic', 'claude-sonnet-4-20250514'))->toBe(200000)
        ->and((new ModelCapabilities)->contextWindow('openai', 'gpt-4o'))->toBe(128000);
});

it('disables tool definitions for models without tool support', function () {
    $conversation = Conversation::factory()->create();
    $fake = Prism::fake([
        TextResponseFake::make()->withText('no tools path'),
    ]);

    $tool = new class implements \App\Agent\Contracts\ToolInterface
    {
        public function name(): string
        {
            return 'echo_tool';
        }

        public function description(): string
        {
            return 'Echo tool';
        }

        public function parameters(): array
        {
            return [
                'type' => 'object',
                'properties' => [
                    'value' => ['type' => 'string'],
                ],
            ];
        }

        public function execute(array $input): ToolResult
        {
            return new ToolResult(true, $input['value'] ?? null);
        }

        public function requiredPermission(): string
        {
            return 'read';
        }
    };

    $orchestrator = new AgentOrchestrator(
        new SystemPromptBuilder([$tool]),
        new ContextManager,
        [$tool],
    );

    $response = $orchestrator->respond('hello', $conversation->id, 'deepseek', 'deepseek-reasoner');

    expect($response)->toBe('no tools path');

    $fake->assertRequest(function (array $requests): void {
        expect($requests[0]->tools())->toBe([]);
    });
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
