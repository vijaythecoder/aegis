<?php

use App\Agent\Middleware\InjectProjectContext;
use App\Models\Agent as AgentModel;
use App\Models\Project;
use App\Models\ProjectKnowledge;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Prompts\AgentPrompt;

uses(RefreshDatabase::class);

function makeDynamicAgentMock(AgentModel $model): Agent
{
    $agent = Mockery::mock(Agent::class);
    $agent->shouldReceive('agentModel')->andReturn($model);

    return $agent;
}

function makeProjectContextPrompt(Agent $agent, string $text): AgentPrompt
{
    $provider = Mockery::mock(TextProvider::class);

    return new AgentPrompt($agent, $text, [], $provider, 'test-model');
}

it('injects project context for agents with assigned tasks', function () {
    $agentModel = AgentModel::factory()->create(['is_active' => true]);
    $project = Project::factory()->create(['title' => 'Home Renovation', 'description' => 'Renovate the kitchen']);
    Task::factory()->create([
        'title' => 'Get contractor quotes',
        'project_id' => $project->id,
        'assigned_type' => 'agent',
        'assigned_id' => $agentModel->id,
        'status' => 'pending',
    ]);

    $agent = makeDynamicAgentMock($agentModel);
    $prompt = makeProjectContextPrompt($agent, 'What should I do next?');

    $middleware = app(InjectProjectContext::class);
    $result = null;

    $middleware->handle($prompt, function (AgentPrompt $p) use (&$result) {
        $result = $p;

        return $p;
    });

    expect($result->prompt)->toContain('Active Project Context')
        ->and($result->prompt)->toContain('Home Renovation')
        ->and($result->prompt)->toContain('Renovate the kitchen')
        ->and($result->prompt)->toContain('Get contractor quotes');
});

it('skips injection when agent has no assigned tasks', function () {
    $agentModel = AgentModel::factory()->create(['is_active' => true]);

    $agent = makeDynamicAgentMock($agentModel);
    $prompt = makeProjectContextPrompt($agent, 'Hello there');

    $middleware = app(InjectProjectContext::class);
    $result = null;

    $middleware->handle($prompt, function (AgentPrompt $p) use (&$result) {
        $result = $p;

        return $p;
    });

    expect($result->prompt)->toBe('Hello there');
});

it('skips injection when agent is not a DynamicAgent', function () {
    $plainAgent = Mockery::mock(Agent::class);
    $provider = Mockery::mock(TextProvider::class);
    $prompt = new AgentPrompt($plainAgent, 'Test message', [], $provider, 'test-model');

    $middleware = app(InjectProjectContext::class);
    $result = null;

    $middleware->handle($prompt, function (AgentPrompt $p) use (&$result) {
        $result = $p;

        return $p;
    });

    expect($result->prompt)->toBe('Test message');
});

it('includes project knowledge in context', function () {
    $agentModel = AgentModel::factory()->create(['is_active' => true]);
    $project = Project::factory()->create(['title' => 'Tax Prep']);
    Task::factory()->create([
        'title' => 'Gather W-2 forms',
        'project_id' => $project->id,
        'assigned_type' => 'agent',
        'assigned_id' => $agentModel->id,
        'status' => 'in_progress',
    ]);

    ProjectKnowledge::factory()->create([
        'project_id' => $project->id,
        'key' => 'deadline',
        'value' => 'April 15, 2026',
    ]);

    $agent = makeDynamicAgentMock($agentModel);
    $prompt = makeProjectContextPrompt($agent, 'What is the deadline?');

    $middleware = app(InjectProjectContext::class);
    $result = null;

    $middleware->handle($prompt, function (AgentPrompt $p) use (&$result) {
        $result = $p;

        return $p;
    });

    expect($result->prompt)->toContain('Project knowledge')
        ->and($result->prompt)->toContain('deadline')
        ->and($result->prompt)->toContain('April 15, 2026');
});

it('only includes tasks with pending or in_progress status', function () {
    $agentModel = AgentModel::factory()->create(['is_active' => true]);
    $project = Project::factory()->create(['title' => 'Active Project']);

    Task::factory()->create([
        'title' => 'Active task',
        'project_id' => $project->id,
        'assigned_type' => 'agent',
        'assigned_id' => $agentModel->id,
        'status' => 'pending',
    ]);
    Task::factory()->create([
        'title' => 'Completed task',
        'project_id' => $project->id,
        'assigned_type' => 'agent',
        'assigned_id' => $agentModel->id,
        'status' => 'completed',
    ]);

    $agent = makeDynamicAgentMock($agentModel);
    $prompt = makeProjectContextPrompt($agent, 'What tasks do I have?');

    $middleware = app(InjectProjectContext::class);
    $result = null;

    $middleware->handle($prompt, function (AgentPrompt $p) use (&$result) {
        $result = $p;

        return $p;
    });

    expect($result->prompt)->toContain('Active task')
        ->and($result->prompt)->not->toContain('Completed task');
});

it('truncates context exceeding 2000 characters', function () {
    $agentModel = AgentModel::factory()->create(['is_active' => true]);
    $project = Project::factory()->create([
        'title' => 'Big Project',
        'description' => str_repeat('Long description. ', 100),
    ]);

    for ($i = 1; $i <= 5; $i++) {
        Task::factory()->create([
            'title' => "Task {$i} with a relatively long title to fill space",
            'project_id' => $project->id,
            'assigned_type' => 'agent',
            'assigned_id' => $agentModel->id,
            'status' => 'pending',
        ]);
    }

    for ($i = 1; $i <= 10; $i++) {
        ProjectKnowledge::factory()->create([
            'project_id' => $project->id,
            'key' => "knowledge_entry_{$i}",
            'value' => str_repeat("Knowledge content {$i}. ", 20),
        ]);
    }

    $agent = makeDynamicAgentMock($agentModel);
    $prompt = makeProjectContextPrompt($agent, 'Summary?');

    $middleware = app(InjectProjectContext::class);
    $result = null;

    $middleware->handle($prompt, function (AgentPrompt $p) use (&$result) {
        $result = $p;

        return $p;
    });

    expect($result->prompt)->toContain('[Project context truncated]');
});
