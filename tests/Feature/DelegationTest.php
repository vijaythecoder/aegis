<?php

use App\Agent\DynamicAgent;
use App\Enums\MessageRole;
use App\Jobs\ExecuteAgentTaskJob;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Task;
use App\Tools\TaskTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tool = app(TaskTool::class);
});

it('creates a delegated task with source_task_id tracking', function () {
    $agentA = Agent::factory()->create(['slug' => 'planner', 'is_active' => true]);
    $agentB = Agent::factory()->create(['slug' => 'researcher', 'is_active' => true]);

    $sourceTask = Task::factory()->create([
        'title' => 'Plan project',
        'assigned_type' => 'agent',
        'assigned_id' => $agentA->id,
    ]);

    Queue::fake();

    $result = (string) $this->tool->handle(new Request([
        'action' => 'create',
        'title' => 'Research competitors',
        'assigned_type' => 'agent',
        'assigned_id' => 'researcher',
        'source_task_id' => $sourceTask->id,
    ]));

    expect($result)->toContain('Task created')
        ->and($result)->toContain('dispatched for background execution')
        ->and($result)->toContain('Delegation depth: 1');

    $delegatedTask = Task::query()->where('title', 'Research competitors')->first();
    expect($delegatedTask)->not->toBeNull()
        ->and($delegatedTask->delegated_from)->toBe($sourceTask->id)
        ->and($delegatedTask->delegation_depth)->toBe(1)
        ->and($delegatedTask->assigned_type)->toBe('agent')
        ->and($delegatedTask->assigned_id)->toBe($agentB->id);

    Queue::assertPushed(ExecuteAgentTaskJob::class);
});

it('increments delegation depth through chain', function () {
    $agentA = Agent::factory()->create(['slug' => 'manager', 'is_active' => true]);
    $agentB = Agent::factory()->create(['slug' => 'analyst', 'is_active' => true]);
    $agentC = Agent::factory()->create(['slug' => 'writer', 'is_active' => true]);

    $task1 = Task::factory()->create([
        'title' => 'Top-level task',
        'assigned_type' => 'agent',
        'assigned_id' => $agentA->id,
        'delegation_depth' => 0,
    ]);

    Queue::fake();

    $this->tool->handle(new Request([
        'action' => 'create',
        'title' => 'Analyze data',
        'assigned_type' => 'agent',
        'assigned_id' => 'analyst',
        'source_task_id' => $task1->id,
    ]));

    $task2 = Task::query()->where('title', 'Analyze data')->first();
    expect($task2->delegation_depth)->toBe(1);

    $this->tool->handle(new Request([
        'action' => 'create',
        'title' => 'Write report',
        'assigned_type' => 'agent',
        'assigned_id' => 'writer',
        'source_task_id' => $task2->id,
    ]));

    $task3 = Task::query()->where('title', 'Write report')->first();
    expect($task3->delegation_depth)->toBe(2)
        ->and($task3->delegated_from)->toBe($task2->id);
});

it('rejects delegation exceeding max depth', function () {
    config(['aegis.delegation.max_depth' => 2]);

    $agent = Agent::factory()->create(['slug' => 'deep-agent', 'is_active' => true]);
    $targetAgent = Agent::factory()->create(['slug' => 'target', 'is_active' => true]);

    $task = Task::factory()->create([
        'title' => 'Deep task',
        'assigned_type' => 'agent',
        'assigned_id' => $agent->id,
        'delegation_depth' => 2,
    ]);

    $result = (string) $this->tool->handle(new Request([
        'action' => 'create',
        'title' => 'Too deep',
        'assigned_type' => 'agent',
        'assigned_id' => 'target',
        'source_task_id' => $task->id,
    ]));

    expect($result)->toContain('delegation depth limit')
        ->and($result)->toContain('exceeded');

    expect(Task::query()->where('title', 'Too deep')->exists())->toBeFalse();
});

it('rejects circular delegation back to originating agent', function () {
    $agent = Agent::factory()->create(['slug' => 'looper', 'is_active' => true]);

    $task = Task::factory()->create([
        'title' => 'Original task',
        'assigned_type' => 'agent',
        'assigned_id' => $agent->id,
    ]);

    $result = (string) $this->tool->handle(new Request([
        'action' => 'create',
        'title' => 'Circular task',
        'assigned_type' => 'agent',
        'assigned_id' => 'looper',
        'source_task_id' => $task->id,
    ]));

    expect($result)->toContain('circular delegation detected');
    expect(Task::query()->where('title', 'Circular task')->exists())->toBeFalse();
});

it('detects delegation context from app container binding', function () {
    $agentA = Agent::factory()->create(['slug' => 'source-agent', 'is_active' => true]);
    $agentB = Agent::factory()->create(['slug' => 'target-agent', 'is_active' => true]);

    $contextTask = Task::factory()->create([
        'title' => 'Context task',
        'assigned_type' => 'agent',
        'assigned_id' => $agentA->id,
        'delegation_depth' => 0,
    ]);

    app()->instance('aegis.current_task_id', $contextTask->id);
    Queue::fake();

    $result = (string) $this->tool->handle(new Request([
        'action' => 'create',
        'title' => 'Auto-detected delegation',
        'assigned_type' => 'agent',
        'assigned_id' => 'target-agent',
    ]));

    expect($result)->toContain('Delegation depth: 1');

    $task = Task::query()->where('title', 'Auto-detected delegation')->first();
    expect($task->delegated_from)->toBe($contextTask->id)
        ->and($task->delegation_depth)->toBe(1);
});

it('source_task_id takes precedence over container context', function () {
    $agentA = Agent::factory()->create(['slug' => 'agent-a', 'is_active' => true]);
    $agentB = Agent::factory()->create(['slug' => 'agent-b', 'is_active' => true]);

    $contextTask = Task::factory()->create([
        'title' => 'Context task',
        'assigned_type' => 'agent',
        'assigned_id' => $agentA->id,
        'delegation_depth' => 0,
    ]);

    $explicitSource = Task::factory()->create([
        'title' => 'Explicit source',
        'assigned_type' => 'agent',
        'assigned_id' => $agentA->id,
        'delegation_depth' => 1,
    ]);

    app()->instance('aegis.current_task_id', $contextTask->id);
    Queue::fake();

    $this->tool->handle(new Request([
        'action' => 'create',
        'title' => 'Precedence test',
        'assigned_type' => 'agent',
        'assigned_id' => 'agent-b',
        'source_task_id' => $explicitSource->id,
    ]));

    $task = Task::query()->where('title', 'Precedence test')->first();
    expect($task->delegated_from)->toBe($explicitSource->id)
        ->and($task->delegation_depth)->toBe(2);
});

it('does not set delegation for user-assigned tasks', function () {
    $agent = Agent::factory()->create(['slug' => 'helper', 'is_active' => true]);

    $sourceTask = Task::factory()->create([
        'title' => 'Source',
        'assigned_type' => 'agent',
        'assigned_id' => $agent->id,
    ]);

    $result = (string) $this->tool->handle(new Request([
        'action' => 'create',
        'title' => 'User task',
        'assigned_type' => 'user',
        'source_task_id' => $sourceTask->id,
    ]));

    $task = Task::query()->where('title', 'User task')->first();
    expect($task->delegated_from)->toBeNull()
        ->and($task->delegation_depth)->toBe(0);
});

it('notifies delegator conversation on task completion', function () {
    DynamicAgent::fake(['Research results are ready.']);

    $agentA = Agent::factory()->create(['name' => 'Planner', 'slug' => 'planner', 'is_active' => true]);
    $agentB = Agent::factory()->create(['name' => 'Researcher', 'slug' => 'researcher', 'is_active' => true]);

    $sourceConversation = Conversation::factory()->create(['agent_id' => $agentA->id]);

    $sourceTask = Task::factory()->create([
        'title' => 'Plan project',
        'assigned_type' => 'agent',
        'assigned_id' => $agentA->id,
    ]);

    $delegatedTask = Task::factory()->create([
        'title' => 'Research competitors',
        'assigned_type' => 'agent',
        'assigned_id' => $agentB->id,
        'delegated_from' => $sourceTask->id,
        'delegation_depth' => 1,
    ]);

    (new ExecuteAgentTaskJob($delegatedTask->id))->handle(app(\App\Agent\AgentRegistry::class));

    $systemMessage = Message::query()
        ->where('conversation_id', $sourceConversation->id)
        ->where('role', MessageRole::System)
        ->latest('id')
        ->first();

    expect($systemMessage)->not->toBeNull()
        ->and($systemMessage->content)->toContain('Researcher')
        ->and($systemMessage->content)->toContain('Research competitors')
        ->and($systemMessage->content)->toContain('Research results are ready');
});

it('does not notify when task has no delegated_from', function () {
    DynamicAgent::fake(['Done.']);

    $agent = Agent::factory()->create(['is_active' => true]);
    $conversation = Conversation::factory()->create(['agent_id' => $agent->id]);

    $task = Task::factory()->create([
        'title' => 'Standalone task',
        'assigned_type' => 'agent',
        'assigned_id' => $agent->id,
        'delegated_from' => null,
    ]);

    $messageCountBefore = Message::query()->where('conversation_id', $conversation->id)->count();

    (new ExecuteAgentTaskJob($task->id))->handle(app(\App\Agent\AgentRegistry::class));

    $messageCountAfter = Message::query()->where('conversation_id', $conversation->id)->count();
    expect($messageCountAfter)->toBe($messageCountBefore);
});

it('binds current task id in container during execution', function () {
    DynamicAgent::fake(['Completed.']);

    $agent = Agent::factory()->create(['is_active' => true]);
    $task = Task::factory()->create([
        'assigned_type' => 'agent',
        'assigned_id' => $agent->id,
    ]);

    (new ExecuteAgentTaskJob($task->id))->handle(app(\App\Agent\AgentRegistry::class));

    expect(app()->bound('aegis.current_task_id'))->toBeTrue()
        ->and(app('aegis.current_task_id'))->toBe($task->id);
});

it('has delegatedFromTask and delegatedTasks relationships', function () {
    $sourceTask = Task::factory()->create(['title' => 'Parent']);
    $childTask = Task::factory()->create([
        'title' => 'Child',
        'delegated_from' => $sourceTask->id,
        'delegation_depth' => 1,
    ]);

    expect($childTask->delegatedFromTask->id)->toBe($sourceTask->id);
    expect($sourceTask->delegatedTasks)->toHaveCount(1);
    expect($sourceTask->delegatedTasks->first()->id)->toBe($childTask->id);
});

it('delegation config defaults to max_depth 3', function () {
    expect(config('aegis.delegation.max_depth'))->toBe(3);
});

it('auto-dispatches delegated tasks regardless of priority', function () {
    $agentA = Agent::factory()->create(['slug' => 'boss', 'is_active' => true]);
    $agentB = Agent::factory()->create(['slug' => 'worker', 'is_active' => true]);

    $sourceTask = Task::factory()->create([
        'assigned_type' => 'agent',
        'assigned_id' => $agentA->id,
    ]);

    Queue::fake();

    $this->tool->handle(new Request([
        'action' => 'create',
        'title' => 'Low priority delegated',
        'assigned_type' => 'agent',
        'assigned_id' => 'worker',
        'priority' => 'low',
        'source_task_id' => $sourceTask->id,
    ]));

    Queue::assertPushed(ExecuteAgentTaskJob::class);
});
