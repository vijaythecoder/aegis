<?php

use App\Agent\AegisAgent;
use App\Agent\AgentRegistry;
use App\Agent\DynamicAgent;
use App\Models\Agent as AgentModel;
use App\Models\Conversation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('resolves agent by id', function () {
    $agentModel = AgentModel::factory()->create();
    $registry = app(AgentRegistry::class);

    $dynamicAgent = $registry->resolve($agentModel->id);

    expect($dynamicAgent)
        ->toBeInstanceOf(DynamicAgent::class)
        ->and($dynamicAgent->agentModel()->id)->toBe($agentModel->id);
});

it('resolves agent by slug', function () {
    AgentModel::factory()->create(['slug' => 'finance-bot']);
    $registry = app(AgentRegistry::class);

    $dynamicAgent = $registry->resolveBySlug('finance-bot');

    expect($dynamicAgent)
        ->toBeInstanceOf(DynamicAgent::class)
        ->and($dynamicAgent->agentModel()->slug)->toBe('finance-bot');
});

it('throws exception for inactive agent', function () {
    $inactive = AgentModel::factory()->inactive()->create();
    $registry = app(AgentRegistry::class);

    $registry->resolve($inactive->id);
})->throws(ModelNotFoundException::class);

it('throws exception for nonexistent agent', function () {
    $registry = app(AgentRegistry::class);

    $registry->resolve(99999);
})->throws(ModelNotFoundException::class);

it('returns default aegis agent', function () {
    $registry = app(AgentRegistry::class);

    $agent = $registry->resolveDefault();

    expect($agent)->toBeInstanceOf(AegisAgent::class);
});

it('returns all active agents', function () {
    AgentModel::factory()->create(['slug' => 'agent-1']);
    AgentModel::factory()->create(['slug' => 'agent-2']);
    AgentModel::factory()->create(['slug' => 'agent-3']);
    AgentModel::factory()->inactive()->create(['slug' => 'agent-4']);
    $registry = app(AgentRegistry::class);

    $agents = $registry->all();

    expect($agents)->toHaveCount(3);
});

it('resolves dynamic agent for conversation with agent_id', function () {
    $agentModel = AgentModel::factory()->create();
    $conversation = Conversation::factory()->create(['agent_id' => $agentModel->id]);
    $registry = app(AgentRegistry::class);

    $agent = $registry->forConversation($conversation);

    expect($agent)
        ->toBeInstanceOf(DynamicAgent::class)
        ->and($agent->agentModel()->id)->toBe($agentModel->id);
});

it('returns default agent for conversation without agent_id', function () {
    $conversation = Conversation::factory()->create(['agent_id' => null]);
    $registry = app(AgentRegistry::class);

    $agent = $registry->forConversation($conversation);

    expect($agent)->toBeInstanceOf(AegisAgent::class);
});

it('falls back to default when conversation agent is deleted', function () {
    $conversation = Conversation::factory()->create(['agent_id' => 99999]);
    $registry = app(AgentRegistry::class);

    $agent = $registry->forConversation($conversation);

    expect($agent)->toBeInstanceOf(AegisAgent::class);
});

it('is registered as singleton', function () {
    $a = app(AgentRegistry::class);
    $b = app(AgentRegistry::class);

    expect($a)->toBe($b);
});
