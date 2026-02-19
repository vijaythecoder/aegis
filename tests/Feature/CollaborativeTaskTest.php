<?php

use App\Agent\DynamicAgent;
use App\Jobs\ExecuteAgentTaskJob;
use App\Livewire\Chat;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Project;
use App\Models\Task;
use App\Tools\TaskTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Tools\Request;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('inserts collaborative message when assigning non-high priority task to agent', function () {
    $agent = Agent::factory()->create(['name' => 'FitCoach', 'slug' => 'fitcoach', 'is_active' => true]);
    $task = Task::factory()->create(['title' => 'Plan workout', 'status' => 'pending', 'priority' => 'medium']);

    $tool = app(TaskTool::class);
    $result = (string) $tool->handle(new Request([
        'action' => 'assign',
        'task_id' => $task->id,
        'assigned_type' => 'agent',
        'assigned_id' => 'fitcoach',
    ]));

    expect($result)->toContain('collaborative work');

    $conversation = Conversation::query()->where('agent_id', $agent->id)->first();
    expect($conversation)->not->toBeNull();

    $message = Message::query()->where('conversation_id', $conversation->id)->first();
    expect($message)->not->toBeNull()
        ->and($message->content)->toContain('Plan workout')
        ->and($message->role->value)->toBe('system');
});

it('dispatches background job when assigning high priority task to agent', function () {
    Queue::fake();

    $agent = Agent::factory()->create(['name' => 'QuickBot', 'slug' => 'quickbot', 'is_active' => true]);
    $task = Task::factory()->create(['title' => 'Urgent task', 'status' => 'pending', 'priority' => 'high']);

    $tool = app(TaskTool::class);
    $result = (string) $tool->handle(new Request([
        'action' => 'assign',
        'task_id' => $task->id,
        'assigned_type' => 'agent',
        'assigned_id' => 'quickbot',
    ]));

    expect($result)->toContain('background execution');
    Queue::assertPushed(ExecuteAgentTaskJob::class);
});

it('uses existing agent conversation for collaborative message', function () {
    $agent = Agent::factory()->create(['name' => 'Coach', 'slug' => 'coach', 'is_active' => true]);
    $existingConvo = Conversation::query()->create(['agent_id' => $agent->id, 'title' => 'Coach']);
    $task = Task::factory()->create(['title' => 'Do thing', 'priority' => 'low']);

    $tool = app(TaskTool::class);
    $tool->handle(new Request([
        'action' => 'assign',
        'task_id' => $task->id,
        'assigned_type' => 'agent',
        'assigned_id' => 'coach',
    ]));

    $message = Message::query()->where('conversation_id', $existingConvo->id)->first();
    expect($message)->not->toBeNull()
        ->and($message->content)->toContain('Do thing');

    expect(Conversation::query()->where('agent_id', $agent->id)->count())->toBe(1);
});

it('includes project context in collaborative message', function () {
    $agent = Agent::factory()->create(['name' => 'Helper', 'slug' => 'helper', 'is_active' => true]);
    $project = Project::factory()->create(['title' => 'Tax Prep']);
    $task = Task::factory()->create([
        'title' => 'Gather docs',
        'project_id' => $project->id,
        'priority' => 'medium',
    ]);

    $tool = app(TaskTool::class);
    $tool->handle(new Request([
        'action' => 'assign',
        'task_id' => $task->id,
        'assigned_type' => 'agent',
        'assigned_id' => 'helper',
    ]));

    $conversation = Conversation::query()->where('agent_id', $agent->id)->first();
    $message = Message::query()->where('conversation_id', $conversation->id)->first();
    expect($message->content)->toContain('Tax Prep');
});

it('shows pending task count in chat for agent conversations', function () {
    DynamicAgent::fake(['Response']);

    $agent = Agent::factory()->create(['name' => 'TestAgent', 'slug' => 'testagent', 'is_active' => true]);
    $conversation = Conversation::query()->create(['agent_id' => $agent->id, 'title' => 'TestAgent']);
    Task::factory()->create([
        'title' => 'Pending task one',
        'assigned_type' => 'agent',
        'assigned_id' => $agent->id,
        'status' => 'pending',
    ]);

    Livewire::test(Chat::class, ['conversationId' => $conversation->id])
        ->assertSee('1 task')
        ->assertSee('Pending task one');
});

it('does not show tasks panel for non-agent conversations', function () {
    $conversation = Conversation::query()->create(['title' => 'Regular chat']);

    Livewire::test(Chat::class, ['conversationId' => $conversation->id])
        ->assertDontSee('task');
});

it('completes a task from chat', function () {
    $agent = Agent::factory()->create(['is_active' => true]);
    $conversation = Conversation::query()->create(['agent_id' => $agent->id, 'title' => 'Agent chat']);

    Message::query()->create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'content' => 'Here is your workout plan for the week.',
    ]);

    $task = Task::factory()->create([
        'title' => 'Create plan',
        'assigned_type' => 'agent',
        'assigned_id' => $agent->id,
        'status' => 'pending',
    ]);

    Livewire::test(Chat::class, ['conversationId' => $conversation->id])
        ->call('completeTaskFromChat', $task->id);

    $task->refresh();
    expect($task->status)->toBe('completed')
        ->and($task->completed_at)->not->toBeNull()
        ->and($task->output)->toContain('workout plan');
});

it('does not complete a task that belongs to a different agent', function () {
    $agent1 = Agent::factory()->create(['is_active' => true]);
    $agent2 = Agent::factory()->create(['is_active' => true]);
    $conversation = Conversation::query()->create(['agent_id' => $agent1->id, 'title' => 'Agent 1']);
    $task = Task::factory()->create([
        'assigned_type' => 'agent',
        'assigned_id' => $agent2->id,
        'status' => 'pending',
    ]);

    Livewire::test(Chat::class, ['conversationId' => $conversation->id])
        ->call('completeTaskFromChat', $task->id);

    expect($task->fresh()->status)->toBe('pending');
});
