<?php

use App\Agent\AegisAgent;
use App\Agent\ProactiveTaskRunner;
use App\Messaging\Adapters\TelegramAdapter;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessagingChannel;
use App\Models\ProactiveTask;
use Database\Seeders\ProactiveTaskSeeder;
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

it('seeds default proactive tasks via seeder', function () {
    (new ProactiveTaskSeeder)->run();

    $tasks = ProactiveTask::all();
    expect($tasks)->toHaveCount(4);

    $names = $tasks->pluck('name')->sort()->values()->all();
    expect($names)->toBe([
        'API Key Expiration',
        'Memory Digest',
        'Morning Briefing',
        'Stale Conversation Nudge',
    ]);

    foreach ($tasks as $task) {
        expect($task->is_active)->toBeFalse('All seeded tasks should be inactive by default');
    }
});

it('seeder is idempotent', function () {
    (new ProactiveTaskSeeder)->run();
    (new ProactiveTaskSeeder)->run();

    expect(ProactiveTask::count())->toBe(4);
});

it('delivers to telegram when channel exists', function () {
    AegisAgent::fake(['Telegram briefing content']);

    $adapter = Mockery::mock(TelegramAdapter::class);
    $adapter->shouldReceive('sendMessage')
        ->once()
        ->with('12345', 'Telegram briefing content', null);

    app()->instance(TelegramAdapter::class, $adapter);

    $conversation = Conversation::create([
        'title' => 'Telegram Session',
        'last_message_at' => now(),
    ]);

    MessagingChannel::create([
        'platform' => 'telegram',
        'platform_channel_id' => '12345',
        'platform_user_id' => '67890',
        'conversation_id' => $conversation->id,
        'active' => true,
    ]);

    $task = ProactiveTask::factory()->due()->create([
        'delivery_channel' => 'telegram',
    ]);

    $runner = app(ProactiveTaskRunner::class);
    $runner->runDueTasks();

    expect(Conversation::count())->toBe(1, 'Only the channel setup conversation should exist, not a proactive task one');
});

it('falls back to chat when no telegram channel exists', function () {
    AegisAgent::fake(['Fallback content']);

    $task = ProactiveTask::factory()->due()->create([
        'name' => 'Telegram Fallback',
        'delivery_channel' => 'telegram',
    ]);

    $runner = app(ProactiveTaskRunner::class);
    $runner->runDueTasks();

    $conversation = Conversation::query()->where('title', 'Telegram Fallback')->first();
    expect($conversation)->not->toBeNull();

    $message = Message::query()->where('conversation_id', $conversation->id)->first();
    expect($message->content)->toBe('Fallback content');
});
