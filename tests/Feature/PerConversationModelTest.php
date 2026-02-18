<?php

use App\Agent\AegisAgent;
use App\Agent\ModelCapabilities;
use App\Agent\ProviderManager;
use App\Livewire\AgentStatus;
use App\Livewire\Chat;
use App\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('chat component has model selector properties', function () {
    Livewire::test(Chat::class)
        ->assertSet('selectedProvider', config('aegis.agent.default_provider'))
        ->assertSet('selectedModel', config('aegis.agent.default_model'));
});

test('chat renders model selector dropdowns', function () {
    Livewire::test(Chat::class)
        ->assertSeeHtml('wire:model.live="selectedProvider"')
        ->assertSeeHtml('wire:model.live="selectedModel"');
});

test('switching conversation loads its stored model', function () {
    $conversation = Conversation::factory()->create([
        'provider' => 'openai',
        'model' => 'gpt-4o',
    ]);

    Livewire::test(Chat::class)
        ->call('onConversationSelected', $conversation->id)
        ->assertSet('selectedProvider', 'openai')
        ->assertSet('selectedModel', 'gpt-4o');
});

test('conversation without stored model uses global default', function () {
    $conversation = Conversation::factory()->create([
        'provider' => null,
        'model' => null,
    ]);

    Livewire::test(Chat::class)
        ->call('onConversationSelected', $conversation->id)
        ->assertSet('selectedProvider', config('aegis.agent.default_provider'));
});

test('changing model persists to conversation', function () {
    $conversation = Conversation::factory()->create();

    Livewire::test(Chat::class, ['conversationId' => $conversation->id])
        ->set('selectedModel', 'gpt-4o')
        ->assertDispatched('model-changed');

    $conversation->refresh();
    expect($conversation->model)->toBe('gpt-4o');
});

test('changing provider resets model to provider default', function () {
    Livewire::test(Chat::class)
        ->set('selectedProvider', 'openai')
        ->assertSet('selectedModel', 'gpt-4o');
});

test('aegis agent withProvider and withModel set overrides', function () {
    $agent = app(AegisAgent::class);
    $agent->forConversation(1, withStorage: false);

    $agent->withProvider('openai')->withModel('gpt-4o');

    $reflection = new ReflectionClass($agent);

    $providerProp = $reflection->getProperty('overrideProvider');
    $providerProp->setAccessible(true);

    $modelProp = $reflection->getProperty('overrideModel');
    $modelProp->setAccessible(true);

    expect($providerProp->getValue($agent))->toBe('openai')
        ->and($modelProp->getValue($agent))->toBe('gpt-4o');
});

test('aegis agent forConversation with storage resets overrides', function () {
    $agent = app(AegisAgent::class);
    $agent->forConversation(1, withStorage: false);
    $agent->withProvider('openai')->withModel('gpt-4o');

    $agent->forConversation(2, withStorage: true);

    $reflection = new ReflectionClass($agent);

    $providerProp = $reflection->getProperty('overrideProvider');
    $providerProp->setAccessible(true);

    $modelProp = $reflection->getProperty('overrideModel');
    $modelProp->setAccessible(true);

    expect($providerProp->getValue($agent))->toBeNull()
        ->and($modelProp->getValue($agent))->toBeNull();
});

test('aegis agent forConversation without storage preserves overrides', function () {
    $agent = app(AegisAgent::class);
    $agent->withProvider('openai')->withModel('gpt-4o');

    $agent->forConversation(2, withStorage: false);

    $reflection = new ReflectionClass($agent);

    $providerProp = $reflection->getProperty('overrideProvider');
    $providerProp->setAccessible(true);

    $modelProp = $reflection->getProperty('overrideModel');
    $modelProp->setAccessible(true);

    expect($providerProp->getValue($agent))->toBe('openai')
        ->and($modelProp->getValue($agent))->toBe('gpt-4o');
});

test('agent status shows conversation model when set', function () {
    $conversation = Conversation::factory()->create([
        'provider' => 'openai',
        'model' => 'gpt-4o',
    ]);

    Livewire::test(AgentStatus::class, ['conversationId' => $conversation->id])
        ->assertSee('openai')
        ->assertSee('gpt-4o');
});

test('agent status shows global default when no conversation model', function () {
    $conversation = Conversation::factory()->create([
        'provider' => null,
        'model' => null,
    ]);

    [$defaultProvider, $defaultModel] = app(ProviderManager::class)->resolve();

    Livewire::test(AgentStatus::class, ['conversationId' => $conversation->id])
        ->assertSee($defaultProvider)
        ->assertSee($defaultModel);
});

test('model capabilities returns dynamic OpenRouter models', function () {
    Cache::forget('openrouter_available_models');

    Http::fake([
        'openrouter.ai/api/v1/models' => Http::response(['data' => [
            [
                'id' => 'anthropic/claude-sonnet-4',
                'name' => 'Claude Sonnet 4',
                'context_length' => 200000,
                'pricing' => ['prompt' => '0.000003', 'completion' => '0.000015'],
                'architecture' => ['input_modalities' => ['text', 'image']],
                'supported_parameters' => ['tools'],
            ],
            [
                'id' => 'moonshot/kimi-k2.5',
                'name' => 'Kimi K2.5',
                'context_length' => 256000,
                'pricing' => ['prompt' => '0.0000006', 'completion' => '0.000003'],
                'architecture' => ['input_modalities' => ['text', 'image']],
                'supported_parameters' => ['tools', 'structured_outputs'],
            ],
        ]], 200),
    ]);

    $capabilities = app(ModelCapabilities::class);
    $models = $capabilities->modelsForProvider('openrouter');

    expect($models)->toContain('anthropic/claude-sonnet-4')
        ->and($models)->toContain('moonshot/kimi-k2.5');
});

test('model capabilities falls back to config for non-openrouter providers', function () {
    $capabilities = app(ModelCapabilities::class);
    $models = $capabilities->modelsForProvider('anthropic');

    expect($models)->toContain('claude-sonnet-4-20250514');
});
