<?php

use App\Agent\AegisAgent;
use App\Agent\ProactiveTaskRunner;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\ProactiveTask;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates proactive task with factory', function () {
    $task = ProactiveTask::factory()->create([
        'name' => 'morning_briefing',
        'schedule' => '0 8 * * 1-5',
        'prompt' => 'Give me a morning briefing',
    ]);

    expect($task->name)->toBe('morning_briefing')
        ->and($task->schedule)->toBe('0 8 * * 1-5')
        ->and($task->is_active)->toBeFalse()
        ->and($task->delivery_channel)->toBe('chat');
});

it('detects due tasks correctly', function () {
    $due = ProactiveTask::factory()->due()->create();
    $notDue = ProactiveTask::factory()->notDue()->create();
    $inactive = ProactiveTask::factory()->create(['is_active' => false]);

    expect($due->isDue())->toBeTrue()
        ->and($notDue->isDue())->toBeFalse()
        ->and($inactive->isDue())->toBeFalse();
});

it('queries due tasks with scope', function () {
    ProactiveTask::factory()->due()->create();
    ProactiveTask::factory()->due()->create();
    ProactiveTask::factory()->notDue()->create();
    ProactiveTask::factory()->create(['is_active' => false]);

    expect(ProactiveTask::query()->due()->count())->toBe(2);
});

it('updates next run after execution', function () {
    $task = ProactiveTask::factory()->due()->create([
        'schedule' => '0 8 * * *',
    ]);

    $task->updateNextRun();

    expect($task->last_run_at)->not->toBeNull()
        ->and($task->next_run_at)->not->toBeNull()
        ->and($task->next_run_at->isFuture())->toBeTrue();
});

it('runs due tasks and delivers to chat', function () {
    AegisAgent::fake(['Here is your morning briefing for today.']);

    $task = ProactiveTask::factory()->due()->create([
        'name' => 'Morning Briefing',
        'prompt' => 'Give me a morning briefing',
    ]);

    $runner = app(ProactiveTaskRunner::class);
    $count = $runner->runDueTasks();

    expect($count)->toBe(1);

    $conversation = Conversation::query()->where('title', 'Morning Briefing')->first();
    expect($conversation)->not->toBeNull();

    $message = Message::query()->where('conversation_id', $conversation->id)->first();
    expect($message->content)->toBe('Here is your morning briefing for today.')
        ->and($message->role->value)->toBe('assistant');

    $task->refresh();
    expect($task->last_run_at)->not->toBeNull()
        ->and($task->next_run_at->isFuture())->toBeTrue();
});

it('skips tasks that are not due', function () {
    AegisAgent::fake(['Should not run']);

    ProactiveTask::factory()->notDue()->create();

    $runner = app(ProactiveTaskRunner::class);
    $count = $runner->runDueTasks();

    expect($count)->toBe(0);

    AegisAgent::assertNeverPrompted();
});

it('continues running other tasks when one fails', function () {
    AegisAgent::fake(fn () => throw new RuntimeException('API error'));

    ProactiveTask::factory()->due()->count(2)->create();

    $runner = app(ProactiveTaskRunner::class);
    $count = $runner->runDueTasks();

    expect($count)->toBe(0);

    $tasks = ProactiveTask::all();
    foreach ($tasks as $task) {
        expect($task->last_run_at)->not->toBeNull('All tasks should update next_run even on failure');
    }
});

it('runs artisan command successfully', function () {
    AegisAgent::fake(['Briefing content']);

    ProactiveTask::factory()->due()->create();

    $this->artisan('aegis:proactive:run')
        ->assertSuccessful()
        ->expectsOutputToContain('Ran 1 proactive task(s).');
});
