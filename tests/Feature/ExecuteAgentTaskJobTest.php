<?php

use App\Agent\DynamicAgent;
use App\Jobs\ExecuteAgentTaskJob;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Project;
use App\Models\ProjectKnowledge;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

it('dispatches to queue', function () {
    Queue::fake();

    $agent = Agent::factory()->create(['is_active' => true]);
    $task = Task::factory()->create([
        'assigned_type' => 'agent',
        'assigned_id' => $agent->id,
    ]);

    ExecuteAgentTaskJob::dispatch($task->id);

    Queue::assertPushed(ExecuteAgentTaskJob::class);
});

it('executes task and sets status lifecycle', function () {
    DynamicAgent::fake(['Here is the workout plan for the week.']);

    $agent = Agent::factory()->create(['name' => 'FitCoach', 'slug' => 'fitcoach', 'is_active' => true]);
    $task = Task::factory()->create([
        'title' => 'Create workout plan',
        'assigned_type' => 'agent',
        'assigned_id' => $agent->id,
        'status' => 'pending',
    ]);

    (new ExecuteAgentTaskJob($task->id))->handle(app(\App\Agent\AgentRegistry::class));

    $task->refresh();
    expect($task->status)->toBe('completed')
        ->and($task->output)->toContain('workout plan')
        ->and($task->completed_at)->not->toBeNull();
});

it('creates a conversation for the agent task', function () {
    DynamicAgent::fake(['Task completed.']);

    $agent = Agent::factory()->create(['is_active' => true]);
    $task = Task::factory()->create([
        'title' => 'Research topic',
        'assigned_type' => 'agent',
        'assigned_id' => $agent->id,
    ]);

    $conversationCountBefore = Conversation::query()->count();

    (new ExecuteAgentTaskJob($task->id))->handle(app(\App\Agent\AgentRegistry::class));

    expect(Conversation::query()->count())->toBe($conversationCountBefore + 1);

    $conversation = Conversation::query()->latest('id')->first();
    expect($conversation->agent_id)->toBe($agent->id)
        ->and($conversation->title)->toContain('Research topic');
});

it('stores output as project knowledge when project exists', function () {
    DynamicAgent::fake(['Found 3 schools within budget range.']);

    $agent = Agent::factory()->create(['is_active' => true]);
    $project = Project::factory()->create(['title' => 'Kid Education']);
    $task = Task::factory()->create([
        'title' => 'Research schools',
        'project_id' => $project->id,
        'assigned_type' => 'agent',
        'assigned_id' => $agent->id,
    ]);

    (new ExecuteAgentTaskJob($task->id))->handle(app(\App\Agent\AgentRegistry::class));

    $knowledge = ProjectKnowledge::query()->where('task_id', $task->id)->first();
    expect($knowledge)->not->toBeNull()
        ->and($knowledge->project_id)->toBe($project->id)
        ->and($knowledge->key)->toBe('Research schools')
        ->and($knowledge->value)->toContain('schools within budget')
        ->and($knowledge->type)->toBe('artifact');
});

it('does not create project knowledge for standalone tasks', function () {
    DynamicAgent::fake(['Done.']);

    $agent = Agent::factory()->create(['is_active' => true]);
    $task = Task::factory()->create([
        'title' => 'Standalone task',
        'project_id' => null,
        'assigned_type' => 'agent',
        'assigned_id' => $agent->id,
    ]);

    (new ExecuteAgentTaskJob($task->id))->handle(app(\App\Agent\AgentRegistry::class));

    expect(ProjectKnowledge::query()->count())->toBe(0);
});

it('resets status to pending on failure', function () {
    DynamicAgent::fake(fn () => throw new RuntimeException('API error'));

    $agent = Agent::factory()->create(['is_active' => true]);
    $task = Task::factory()->create([
        'assigned_type' => 'agent',
        'assigned_id' => $agent->id,
        'status' => 'pending',
    ]);

    try {
        (new ExecuteAgentTaskJob($task->id))->handle(app(\App\Agent\AgentRegistry::class));
    } catch (RuntimeException) {
    }

    $task->refresh();
    expect($task->status)->toBe('pending')
        ->and($task->output)->toBeNull();
});

it('skips execution when task not found', function () {
    (new ExecuteAgentTaskJob(999))->handle(app(\App\Agent\AgentRegistry::class));

    expect(Task::query()->count())->toBe(0);
});

it('skips execution when task not assigned to agent', function () {
    $task = Task::factory()->create([
        'assigned_type' => 'user',
        'assigned_id' => null,
    ]);

    (new ExecuteAgentTaskJob($task->id))->handle(app(\App\Agent\AgentRegistry::class));

    $task->refresh();
    expect($task->status)->toBe('pending');
});

it('includes project context in the prompt', function () {
    DynamicAgent::fake(['Completed with context.']);

    $agent = Agent::factory()->create(['is_active' => true]);
    $project = Project::factory()->create([
        'title' => 'Tax Prep 2026',
        'description' => 'Gather all tax documents',
    ]);
    $task = Task::factory()->create([
        'title' => 'Collect W-2 forms',
        'description' => 'Get W-2 from current employer',
        'project_id' => $project->id,
        'assigned_type' => 'agent',
        'assigned_id' => $agent->id,
    ]);

    (new ExecuteAgentTaskJob($task->id))->handle(app(\App\Agent\AgentRegistry::class));

    $task->refresh();
    expect($task->status)->toBe('completed')
        ->and($task->output)->not->toBeEmpty();
});
