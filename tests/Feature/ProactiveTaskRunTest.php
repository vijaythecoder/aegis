<?php

use App\Agent\AegisAgent;
use App\Agent\ProactiveTaskRunner;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ProactiveTask;
use App\Models\ProactiveTaskRun;
use App\Tools\ProactiveTaskTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

it('logs a successful execution run', function () {
    AegisAgent::fake(['Here is your morning briefing.']);

    $task = ProactiveTask::factory()->due()->create([
        'name' => 'Morning Briefing',
        'prompt' => 'Give me a morning briefing',
    ]);

    $runner = app(ProactiveTaskRunner::class);
    $runner->runDueTasks();

    $run = ProactiveTaskRun::query()->where('proactive_task_id', $task->id)->first();

    expect($run)->not->toBeNull()
        ->and($run->status)->toBe('success')
        ->and($run->started_at)->not->toBeNull()
        ->and($run->completed_at)->not->toBeNull()
        ->and($run->response_summary)->toContain('Morning Briefing')
        ->and($run->delivery_status)->toBe('sent')
        ->and($run->error_message)->toBeNull();
});

it('logs a failed execution run with error message', function () {
    AegisAgent::fake(fn () => throw new RuntimeException('API quota exceeded'));

    $task = ProactiveTask::factory()->due()->create([
        'name' => 'Failing Task',
        'prompt' => 'This will fail',
    ]);

    $runner = app(ProactiveTaskRunner::class);
    $runner->runDueTasks();

    $run = ProactiveTaskRun::query()->where('proactive_task_id', $task->id)->first();

    expect($run)->not->toBeNull()
        ->and($run->status)->toBe('failed')
        ->and($run->error_message)->toContain('API quota exceeded')
        ->and($run->delivery_status)->toBe('failed')
        ->and($run->response_summary)->toBeNull();
});

it('delivers failure alert to chat on task failure', function () {
    AegisAgent::fake(fn () => throw new RuntimeException('Connection timeout'));

    $task = ProactiveTask::factory()->due()->create([
        'name' => 'Alerting Task',
        'prompt' => 'This triggers an alert',
    ]);

    $runner = app(ProactiveTaskRunner::class);
    $runner->runDueTasks();

    $alertConversation = Conversation::query()->where('title', 'Alerting Task')->first();
    expect($alertConversation)->not->toBeNull();

    $alertMessage = Message::query()->where('conversation_id', $alertConversation->id)->first();
    expect($alertMessage->content)->toContain('failed')
        ->and($alertMessage->content)->toContain('Connection timeout');
});

it('records token usage from response', function () {
    AegisAgent::fake(['Token tracked response']);

    $task = ProactiveTask::factory()->due()->create([
        'name' => 'Token Tracker',
        'prompt' => 'Track my tokens',
    ]);

    $runner = app(ProactiveTaskRunner::class);
    $runner->runDueTasks();

    $run = ProactiveTaskRun::query()->where('proactive_task_id', $task->id)->first();

    expect($run)->not->toBeNull()
        ->and($run->tokens_used)->toBeInt();
});

it('creates run records for multiple due tasks', function () {
    AegisAgent::fake(['Response 1', 'Response 2']);

    ProactiveTask::factory()->due()->count(2)->create();

    $runner = app(ProactiveTaskRunner::class);
    $count = $runner->runDueTasks();

    expect($count)->toBe(2)
        ->and(ProactiveTaskRun::query()->count())->toBe(2)
        ->and(ProactiveTaskRun::query()->successful()->count())->toBe(2);
});

it('has task relationship on run model', function () {
    $task = ProactiveTask::factory()->create(['name' => 'Related Task']);
    $run = ProactiveTaskRun::factory()->create([
        'proactive_task_id' => $task->id,
    ]);

    expect($run->task->name)->toBe('Related Task');
});

it('has runs relationship on task model', function () {
    $task = ProactiveTask::factory()->create();
    ProactiveTaskRun::factory()->count(3)->create(['proactive_task_id' => $task->id]);

    expect($task->runs)->toHaveCount(3);
});

it('has latestRun relationship on task model', function () {
    $task = ProactiveTask::factory()->create();

    ProactiveTaskRun::factory()->create([
        'proactive_task_id' => $task->id,
        'created_at' => now()->subHour(),
        'response_summary' => 'Old run',
    ]);

    ProactiveTaskRun::factory()->create([
        'proactive_task_id' => $task->id,
        'created_at' => now(),
        'response_summary' => 'Latest run',
    ]);

    $task->refresh();
    expect($task->latestRun->response_summary)->toBe('Latest run');
});

it('calculates duration in seconds', function () {
    $run = ProactiveTaskRun::factory()->create([
        'started_at' => now()->subSeconds(15),
        'completed_at' => now(),
    ]);

    expect($run->durationInSeconds())->toBeGreaterThanOrEqual(14)
        ->and($run->durationInSeconds())->toBeLessThanOrEqual(16);
});

it('scopes today runs correctly', function () {
    $task = ProactiveTask::factory()->create();

    ProactiveTaskRun::factory()->create([
        'proactive_task_id' => $task->id,
        'created_at' => now(),
    ]);

    ProactiveTaskRun::factory()->create([
        'proactive_task_id' => $task->id,
        'created_at' => now()->subDays(2),
    ]);

    expect(ProactiveTaskRun::query()->today()->count())->toBe(1);
});

it('shows history via tool action', function () {
    $tool = app(ProactiveTaskTool::class);

    $task = ProactiveTask::factory()->create(['name' => 'History Task']);

    ProactiveTaskRun::factory()->create([
        'proactive_task_id' => $task->id,
        'status' => 'success',
        'response_summary' => 'Everything went well',
        'tokens_used' => 500,
    ]);

    ProactiveTaskRun::factory()->failed()->create([
        'proactive_task_id' => $task->id,
        'error_message' => 'API timeout',
    ]);

    $result = (string) $tool->handle(new Request([
        'action' => 'history',
        'task_id' => $task->id,
    ]));

    expect($result)->toContain('History Task')
        ->and($result)->toContain('✅')
        ->and($result)->toContain('❌')
        ->and($result)->toContain('API timeout');
});

it('returns empty history message', function () {
    $tool = app(ProactiveTaskTool::class);

    $task = ProactiveTask::factory()->create(['name' => 'No Runs']);

    $result = (string) $tool->handle(new Request([
        'action' => 'history',
        'task_id' => $task->id,
    ]));

    expect($result)->toContain('No execution history');
});

it('requires task_id for history action', function () {
    $tool = app(ProactiveTaskTool::class);

    $result = (string) $tool->handle(new Request(['action' => 'history']));

    expect($result)->toContain('requires task_id');
});

it('shows daily digest via tool action', function () {
    $tool = app(ProactiveTaskTool::class);

    $task1 = ProactiveTask::factory()->create(['name' => 'Morning Brief']);
    $task2 = ProactiveTask::factory()->create(['name' => 'News Scan']);

    ProactiveTaskRun::factory()->create([
        'proactive_task_id' => $task1->id,
        'status' => 'success',
        'response_summary' => 'Briefing delivered',
        'tokens_used' => 300,
        'created_at' => now(),
    ]);

    ProactiveTaskRun::factory()->failed()->create([
        'proactive_task_id' => $task2->id,
        'error_message' => 'Provider down',
        'created_at' => now(),
    ]);

    $result = (string) $tool->handle(new Request(['action' => 'digest']));

    expect($result)->toContain('2 runs')
        ->and($result)->toContain('1 ✅')
        ->and($result)->toContain('1 ❌')
        ->and($result)->toContain('Morning Brief')
        ->and($result)->toContain('Provider down');
});

it('shows empty digest when no runs today', function () {
    $tool = app(ProactiveTaskTool::class);

    $result = (string) $tool->handle(new Request(['action' => 'digest']));

    expect($result)->toContain('No automations have run today');
});

it('includes last run status in task list', function () {
    AegisAgent::fake(['Success response']);

    $task = ProactiveTask::factory()->due()->create([
        'name' => 'Listed Task',
    ]);

    $runner = app(ProactiveTaskRunner::class);
    $runner->runDueTasks();

    $tool = app(ProactiveTaskTool::class);
    $result = (string) $tool->handle(new Request(['action' => 'list']));

    expect($result)->toContain('Listed Task')
        ->and($result)->toContain('✅');
});

it('factory creates valid run records', function () {
    $run = ProactiveTaskRun::factory()->create();

    expect($run->status)->toBe('success')
        ->and($run->started_at)->not->toBeNull()
        ->and($run->completed_at)->not->toBeNull()
        ->and($run->delivery_status)->toBe('sent');
});

it('factory creates failed run records', function () {
    $run = ProactiveTaskRun::factory()->failed()->create();

    expect($run->status)->toBe('failed')
        ->and($run->error_message)->not->toBeNull()
        ->and($run->tokens_used)->toBe(0);
});
