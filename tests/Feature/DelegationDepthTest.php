<?php

use App\Models\Agent;
use App\Models\Task;
use App\Tools\TaskTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tool = app(TaskTool::class);
});

// --- getDelegationChain() ---

it('getDelegationChain returns empty collection for root task', function () {
    $task = Task::factory()->create([
        'delegated_from' => null,
        'delegation_depth' => 0,
    ]);

    $chain = $task->getDelegationChain();

    expect($chain)->toBeEmpty();
});

it('getDelegationChain returns single parent for depth-1 task', function () {
    $root = Task::factory()->create([
        'title' => 'Root',
        'delegated_from' => null,
        'delegation_depth' => 0,
    ]);

    $child = Task::factory()->create([
        'title' => 'Child',
        'delegated_from' => $root->id,
        'delegation_depth' => 1,
    ]);

    $chain = $child->getDelegationChain();

    expect($chain)->toHaveCount(1)
        ->and($chain->first()->id)->toBe($root->id);
});

it('getDelegationChain walks full chain for deep delegation', function () {
    $agentA = Agent::factory()->create(['slug' => 'alpha']);
    $agentB = Agent::factory()->create(['slug' => 'beta']);
    $agentC = Agent::factory()->create(['slug' => 'gamma']);

    $task1 = Task::factory()->create([
        'title' => 'Level 0',
        'assigned_type' => 'agent',
        'assigned_id' => $agentA->id,
        'delegation_depth' => 0,
    ]);

    $task2 = Task::factory()->create([
        'title' => 'Level 1',
        'assigned_type' => 'agent',
        'assigned_id' => $agentB->id,
        'delegated_from' => $task1->id,
        'delegation_depth' => 1,
    ]);

    $task3 = Task::factory()->create([
        'title' => 'Level 2',
        'assigned_type' => 'agent',
        'assigned_id' => $agentC->id,
        'delegated_from' => $task2->id,
        'delegation_depth' => 2,
    ]);

    $chain = $task3->getDelegationChain();

    expect($chain)->toHaveCount(2)
        ->and($chain->pluck('id')->toArray())->toBe([$task2->id, $task1->id]);
});

// --- hasAgentInDelegationChain() ---

it('hasAgentInDelegationChain detects agent on current task', function () {
    $agent = Agent::factory()->create(['slug' => 'checker']);

    $task = Task::factory()->create([
        'assigned_type' => 'agent',
        'assigned_id' => $agent->id,
        'delegation_depth' => 0,
    ]);

    expect($task->hasAgentInDelegationChain($agent->id))->toBeTrue();
});

it('hasAgentInDelegationChain detects agent in parent chain', function () {
    $agentA = Agent::factory()->create(['slug' => 'origin']);
    $agentB = Agent::factory()->create(['slug' => 'middle']);

    $task1 = Task::factory()->create([
        'assigned_type' => 'agent',
        'assigned_id' => $agentA->id,
        'delegation_depth' => 0,
    ]);

    $task2 = Task::factory()->create([
        'assigned_type' => 'agent',
        'assigned_id' => $agentB->id,
        'delegated_from' => $task1->id,
        'delegation_depth' => 1,
    ]);

    // agentA appears in the chain (parent task)
    expect($task2->hasAgentInDelegationChain($agentA->id))->toBeTrue();
});

it('hasAgentInDelegationChain returns false for absent agent', function () {
    $agentA = Agent::factory()->create(['slug' => 'present']);
    $agentB = Agent::factory()->create(['slug' => 'absent']);

    $task = Task::factory()->create([
        'assigned_type' => 'agent',
        'assigned_id' => $agentA->id,
        'delegation_depth' => 0,
    ]);

    expect($task->hasAgentInDelegationChain($agentB->id))->toBeFalse();
});

// --- 3-step circular: A → B → C → A ---

it('detects 3-step circular delegation A to B to C to A', function () {
    $agentA = Agent::factory()->create(['slug' => 'a-agent', 'is_active' => true]);
    $agentB = Agent::factory()->create(['slug' => 'b-agent', 'is_active' => true]);
    $agentC = Agent::factory()->create(['slug' => 'c-agent', 'is_active' => true]);

    // A creates root task
    $taskA = Task::factory()->create([
        'title' => 'Task A',
        'assigned_type' => 'agent',
        'assigned_id' => $agentA->id,
        'delegation_depth' => 0,
    ]);

    Queue::fake();

    // A delegates to B (depth 1)
    $this->tool->handle(new Request([
        'action' => 'create',
        'title' => 'Task B',
        'assigned_type' => 'agent',
        'assigned_id' => 'b-agent',
        'source_task_id' => $taskA->id,
    ]));

    $taskB = Task::query()->where('title', 'Task B')->first();
    expect($taskB->delegation_depth)->toBe(1);

    // B delegates to C (depth 2)
    $this->tool->handle(new Request([
        'action' => 'create',
        'title' => 'Task C',
        'assigned_type' => 'agent',
        'assigned_id' => 'c-agent',
        'source_task_id' => $taskB->id,
    ]));

    $taskC = Task::query()->where('title', 'Task C')->first();
    expect($taskC->delegation_depth)->toBe(2);

    // C tries to delegate back to A → circular!
    $result = (string) $this->tool->handle(new Request([
        'action' => 'create',
        'title' => 'Back to A',
        'assigned_type' => 'agent',
        'assigned_id' => 'a-agent',
        'source_task_id' => $taskC->id,
    ]));

    expect($result)->toContain('circular delegation detected')
        ->and($result)->toContain('delegation chain');
    expect(Task::query()->where('title', 'Back to A')->exists())->toBeFalse();
});

// --- Depth 3 succeeds, depth 4 fails (default max_depth = 3) ---

it('allows delegation at depth 3 with default max_depth', function () {
    $agents = [];
    foreach (['d-one', 'd-two', 'd-three', 'd-four'] as $slug) {
        $agents[] = Agent::factory()->create(['slug' => $slug, 'is_active' => true]);
    }

    Queue::fake();

    $task0 = Task::factory()->create([
        'title' => 'Depth 0',
        'assigned_type' => 'agent',
        'assigned_id' => $agents[0]->id,
        'delegation_depth' => 0,
    ]);

    // Depth 0 → 1
    $this->tool->handle(new Request([
        'action' => 'create',
        'title' => 'Depth 1',
        'assigned_type' => 'agent',
        'assigned_id' => 'd-two',
        'source_task_id' => $task0->id,
    ]));

    $task1 = Task::query()->where('title', 'Depth 1')->first();
    expect($task1->delegation_depth)->toBe(1);

    // Depth 1 → 2
    $this->tool->handle(new Request([
        'action' => 'create',
        'title' => 'Depth 2',
        'assigned_type' => 'agent',
        'assigned_id' => 'd-three',
        'source_task_id' => $task1->id,
    ]));

    $task2 = Task::query()->where('title', 'Depth 2')->first();
    expect($task2->delegation_depth)->toBe(2);

    // Depth 2 → 3 (should succeed — max is 3)
    $result = (string) $this->tool->handle(new Request([
        'action' => 'create',
        'title' => 'Depth 3',
        'assigned_type' => 'agent',
        'assigned_id' => 'd-four',
        'source_task_id' => $task2->id,
    ]));

    expect($result)->toContain('Task created')
        ->and($result)->toContain('Delegation depth: 3');

    $task3 = Task::query()->where('title', 'Depth 3')->first();
    expect($task3->delegation_depth)->toBe(3);
});

it('rejects delegation at depth 4 with default max_depth 3', function () {
    $agents = [];
    foreach (['e-one', 'e-two', 'e-three', 'e-four', 'e-five'] as $slug) {
        $agents[] = Agent::factory()->create(['slug' => $slug, 'is_active' => true]);
    }

    // Build chain directly via factory to avoid creating 4 tool calls
    $task0 = Task::factory()->create([
        'assigned_type' => 'agent',
        'assigned_id' => $agents[0]->id,
        'delegation_depth' => 0,
    ]);

    $task3 = Task::factory()->create([
        'assigned_type' => 'agent',
        'assigned_id' => $agents[3]->id,
        'delegated_from' => $task0->id,
        'delegation_depth' => 3,
    ]);

    // Depth 3 → 4 should be rejected
    $result = (string) $this->tool->handle(new Request([
        'action' => 'create',
        'title' => 'Depth 4 attempt',
        'assigned_type' => 'agent',
        'assigned_id' => 'e-five',
        'source_task_id' => $task3->id,
    ]));

    expect($result)->toContain('delegation depth limit')
        ->and($result)->toContain('exceeded');
    expect(Task::query()->where('title', 'Depth 4 attempt')->exists())->toBeFalse();
});

// --- Configurable max_depth ---

it('respects custom max_depth configuration', function () {
    config(['aegis.delegation.max_depth' => 5]);

    $agents = [];
    foreach (['f-one', 'f-two'] as $slug) {
        $agents[] = Agent::factory()->create(['slug' => $slug, 'is_active' => true]);
    }

    // Task at depth 4 — would fail with default max_depth=3 but should succeed with max_depth=5
    $task4 = Task::factory()->create([
        'assigned_type' => 'agent',
        'assigned_id' => $agents[0]->id,
        'delegation_depth' => 4,
    ]);

    Queue::fake();

    $result = (string) $this->tool->handle(new Request([
        'action' => 'create',
        'title' => 'Depth 5 with custom limit',
        'assigned_type' => 'agent',
        'assigned_id' => 'f-two',
        'source_task_id' => $task4->id,
    ]));

    expect($result)->toContain('Task created')
        ->and($result)->toContain('Delegation depth: 5');
});

it('rejects when exceeding custom max_depth', function () {
    config(['aegis.delegation.max_depth' => 5]);

    $agents = [];
    foreach (['g-one', 'g-two'] as $slug) {
        $agents[] = Agent::factory()->create(['slug' => $slug, 'is_active' => true]);
    }

    $task5 = Task::factory()->create([
        'assigned_type' => 'agent',
        'assigned_id' => $agents[0]->id,
        'delegation_depth' => 5,
    ]);

    $result = (string) $this->tool->handle(new Request([
        'action' => 'create',
        'title' => 'Depth 6 too deep',
        'assigned_type' => 'agent',
        'assigned_id' => 'g-two',
        'source_task_id' => $task5->id,
    ]));

    expect($result)->toContain('delegation depth limit (5) exceeded');
});

// --- circular_check config toggle ---

it('allows circular delegation when circular_check is disabled', function () {
    config(['aegis.delegation.circular_check' => false]);

    $agent = Agent::factory()->create(['slug' => 'self-delegator', 'is_active' => true]);

    $task = Task::factory()->create([
        'title' => 'Self task',
        'assigned_type' => 'agent',
        'assigned_id' => $agent->id,
        'delegation_depth' => 0,
    ]);

    Queue::fake();

    $result = (string) $this->tool->handle(new Request([
        'action' => 'create',
        'title' => 'Circular allowed',
        'assigned_type' => 'agent',
        'assigned_id' => 'self-delegator',
        'source_task_id' => $task->id,
    ]));

    // Should succeed because circular check is off
    expect($result)->toContain('Task created')
        ->and($result)->not->toContain('circular delegation');

    expect(Task::query()->where('title', 'Circular allowed')->exists())->toBeTrue();
});

// --- ExecuteAgentTaskJob depth guard ---

it('refuses to execute task that exceeds max depth in job', function () {
    $agent = Agent::factory()->create(['is_active' => true]);

    $task = Task::factory()->create([
        'assigned_type' => 'agent',
        'assigned_id' => $agent->id,
        'delegation_depth' => 10,
        'status' => 'pending',
    ]);

    config(['aegis.delegation.max_depth' => 3]);

    (new \App\Jobs\ExecuteAgentTaskJob($task->id))->handle(app(\App\Agent\AgentRegistry::class));

    $task->refresh();
    expect($task->status)->toBe('cancelled')
        ->and($task->output)->toContain('depth limit');
});
