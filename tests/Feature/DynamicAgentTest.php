<?php

use App\Agent\DynamicAgent;
use App\Models\Agent as AgentModel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Contracts\Agent as AgentContract;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;

uses(RefreshDatabase::class);

it('implements all required interfaces', function () {
    $agentModel = AgentModel::factory()->create();
    $dynamicAgent = app()->make(DynamicAgent::class, ['agentModel' => $agentModel]);

    expect($dynamicAgent)
        ->toBeInstanceOf(AgentContract::class)
        ->toBeInstanceOf(Conversational::class)
        ->toBeInstanceOf(HasMiddleware::class)
        ->toBeInstanceOf(HasTools::class);
});

it('uses agent persona in instructions', function () {
    $agentModel = AgentModel::factory()->withPersona('You are a finance expert.')->create();
    $dynamicAgent = app()->make(DynamicAgent::class, ['agentModel' => $agentModel]);

    $instructions = $dynamicAgent->instructions();

    expect((string) $instructions)
        ->toContain('## Agent Identity')
        ->toContain('finance expert');
});

it('uses agent provider and model when set', function () {
    config(['aegis.agent.failover_enabled' => false]);

    $agentModel = AgentModel::factory()->create([
        'provider' => 'openai',
        'model' => 'gpt-4o',
    ]);

    $dynamicAgent = app()->make(DynamicAgent::class, ['agentModel' => $agentModel]);

    expect($dynamicAgent->provider())->toBe('openai')
        ->and($dynamicAgent->model())->toBe('gpt-4o');
});

it('falls back to default provider model when agent has none', function () {
    $agentModel = AgentModel::factory()->create([
        'provider' => null,
        'model' => null,
    ]);

    $dynamicAgent = app()->make(DynamicAgent::class, ['agentModel' => $agentModel]);

    expect($dynamicAgent->model())->toBeString()->not->toBeEmpty();
});

it('returns all tools when agent has no restrictions', function () {
    $agentModel = AgentModel::factory()->create();
    $dynamicAgent = app()->make(DynamicAgent::class, ['agentModel' => $agentModel]);

    $expectedTools = collect(app(\App\Tools\ToolRegistry::class)->all())
        ->filter(fn (mixed $tool): bool => $tool instanceof \Laravel\Ai\Contracts\Tool || $tool instanceof \Laravel\Ai\Providers\Tools\ProviderTool)
        ->values();

    $returnedTools = collect($dynamicAgent->tools());

    expect($returnedTools->count())->toBe($expectedTools->count());
});

it('filters tools when agent has restrictions', function () {
    $agentModel = AgentModel::factory()->create();
    $agentModel->tools()->create(['tool_class' => 'App\\Tools\\WebSearchTool']);

    $dynamicAgent = app()->make(DynamicAgent::class, ['agentModel' => $agentModel]);
    $tools = collect($dynamicAgent->tools());

    $tools->each(fn ($tool) => expect(get_class($tool))->toBe('App\\Tools\\WebSearchTool'));
});

it('returns middleware array', function () {
    $agentModel = AgentModel::factory()->create();
    $dynamicAgent = app()->make(DynamicAgent::class, ['agentModel' => $agentModel]);

    expect($dynamicAgent->middleware())->toBeArray()->not->toBeEmpty();
});

it('exposes the underlying agent model', function () {
    $agentModel = AgentModel::factory()->create(['name' => 'TestBot']);
    $dynamicAgent = app()->make(DynamicAgent::class, ['agentModel' => $agentModel]);

    expect($dynamicAgent->agentModel()->name)->toBe('TestBot');
});
