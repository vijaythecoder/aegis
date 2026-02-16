<?php

use App\Models\ProactiveTask;
use App\Tools\ProactiveTaskTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tool = app(ProactiveTaskTool::class);
});

it('implements the SDK Tool contract', function () {
    expect($this->tool)->toBeInstanceOf(\Laravel\Ai\Contracts\Tool::class);
});

it('has correct name and description', function () {
    expect($this->tool->name())->toBe('manage_automation')
        ->and((string) $this->tool->description())->toContain('automated tasks');
});

it('creates a proactive task', function () {
    $result = (string) $this->tool->handle(new Request([
        'action' => 'create',
        'name' => 'Morning Digest',
        'schedule' => '0 8 * * *',
        'prompt' => 'Send me a morning digest with top news.',
        'delivery_channel' => 'telegram',
    ]));

    expect($result)->toContain('Automation created')
        ->and($result)->toContain('Morning Digest')
        ->and($result)->toContain('telegram');

    $task = ProactiveTask::query()->where('name', 'Morning Digest')->first();
    expect($task)->not->toBeNull()
        ->and($task->schedule)->toBe('0 8 * * *')
        ->and($task->delivery_channel)->toBe('telegram')
        ->and($task->is_active)->toBeTrue()
        ->and($task->next_run_at)->not->toBeNull();
});

it('creates task with default channel and active state', function () {
    $result = (string) $this->tool->handle(new Request([
        'action' => 'create',
        'name' => 'Weekly Review',
        'schedule' => '0 18 * * 0',
        'prompt' => 'Review my week.',
    ]));

    $task = ProactiveTask::query()->where('name', 'Weekly Review')->first();
    expect($task->delivery_channel)->toBe('chat')
        ->and($task->is_active)->toBeTrue();
});

it('rejects task with missing name', function () {
    $result = (string) $this->tool->handle(new Request([
        'action' => 'create',
        'schedule' => '0 8 * * *',
        'prompt' => 'Do something.',
    ]));

    expect($result)->toContain('name is required');
});

it('rejects task with invalid cron expression', function () {
    $result = (string) $this->tool->handle(new Request([
        'action' => 'create',
        'name' => 'Bad Schedule',
        'schedule' => 'every morning',
        'prompt' => 'Do something.',
    ]));

    expect($result)->toContain('not a valid cron expression');
});

it('lists all tasks', function () {
    ProactiveTask::query()->create([
        'name' => 'Task A',
        'schedule' => '0 8 * * *',
        'prompt' => 'Do A.',
        'is_active' => true,
    ]);

    ProactiveTask::query()->create([
        'name' => 'Task B',
        'schedule' => '0 18 * * 0',
        'prompt' => 'Do B.',
        'is_active' => false,
    ]);

    $result = (string) $this->tool->handle(new Request(['action' => 'list']));

    expect($result)->toContain('Task A')
        ->and($result)->toContain('active')
        ->and($result)->toContain('Task B')
        ->and($result)->toContain('paused');
});

it('returns message when no tasks exist', function () {
    $result = (string) $this->tool->handle(new Request(['action' => 'list']));

    expect($result)->toContain('No automated tasks');
});

it('updates a task', function () {
    $task = ProactiveTask::query()->create([
        'name' => 'Old Name',
        'schedule' => '0 8 * * *',
        'prompt' => 'Old prompt.',
        'is_active' => true,
    ]);

    $result = (string) $this->tool->handle(new Request([
        'action' => 'update',
        'task_id' => $task->id,
        'name' => 'New Name',
        'schedule' => '30 9 * * 1-5',
    ]));

    expect($result)->toContain('updated successfully');

    $task->refresh();
    expect($task->name)->toBe('New Name')
        ->and($task->schedule)->toBe('30 9 * * 1-5')
        ->and($task->prompt)->toBe('Old prompt.');
});

it('deletes a task', function () {
    $task = ProactiveTask::query()->create([
        'name' => 'To Delete',
        'schedule' => '0 8 * * *',
        'prompt' => 'Delete me.',
    ]);

    $result = (string) $this->tool->handle(new Request([
        'action' => 'delete',
        'task_id' => $task->id,
    ]));

    expect($result)->toContain('Deleted')
        ->and($result)->toContain('To Delete')
        ->and(ProactiveTask::query()->find($task->id))->toBeNull();
});

it('toggles a task on and off', function () {
    $task = ProactiveTask::query()->create([
        'name' => 'Toggle Me',
        'schedule' => '0 8 * * *',
        'prompt' => 'Toggle test.',
        'is_active' => false,
    ]);

    $result = (string) $this->tool->handle(new Request([
        'action' => 'toggle',
        'task_id' => $task->id,
    ]));

    expect($result)->toContain('activated');
    $task->refresh();
    expect($task->is_active)->toBeTrue()
        ->and($task->next_run_at)->not->toBeNull();

    $result = (string) $this->tool->handle(new Request([
        'action' => 'toggle',
        'task_id' => $task->id,
    ]));

    expect($result)->toContain('paused');
    $task->refresh();
    expect($task->is_active)->toBeFalse();
});

it('returns error for non-existent task', function () {
    $result = (string) $this->tool->handle(new Request([
        'action' => 'delete',
        'task_id' => 999,
    ]));

    expect($result)->toContain('No tasks found');
});

it('replaces duplicate active tasks with same schedule and channel', function () {
    ProactiveTask::query()->create([
        'name' => 'Morning Digest',
        'schedule' => '0 8 * * *',
        'prompt' => 'Send digest.',
        'delivery_channel' => 'telegram',
        'is_active' => true,
    ]);

    $result = (string) $this->tool->handle(new Request([
        'action' => 'create',
        'name' => 'Better Morning Digest',
        'schedule' => '0 8 * * *',
        'prompt' => 'Send a better digest.',
        'delivery_channel' => 'telegram',
    ]));

    expect($result)->toContain('Replaced 1 existing duplicate')
        ->and($result)->toContain('Morning Digest')
        ->and($result)->toContain('Better Morning Digest')
        ->and(ProactiveTask::query()->count())->toBe(1);

    $task = ProactiveTask::query()->first();
    expect($task->name)->toBe('Better Morning Digest')
        ->and($task->prompt)->toBe('Send a better digest.');
});

it('allows duplicate schedule on different channel', function () {
    ProactiveTask::query()->create([
        'name' => 'Chat Digest',
        'schedule' => '0 8 * * *',
        'prompt' => 'Send digest.',
        'delivery_channel' => 'chat',
        'is_active' => true,
    ]);

    $result = (string) $this->tool->handle(new Request([
        'action' => 'create',
        'name' => 'Telegram Digest',
        'schedule' => '0 8 * * *',
        'prompt' => 'Send digest.',
        'delivery_channel' => 'telegram',
    ]));

    expect($result)->toContain('Automation created')
        ->and(ProactiveTask::query()->count())->toBe(2);
});

it('allows creating task when existing duplicate is inactive', function () {
    ProactiveTask::query()->create([
        'name' => 'Paused Digest',
        'schedule' => '0 8 * * *',
        'prompt' => 'Send digest.',
        'delivery_channel' => 'telegram',
        'is_active' => false,
    ]);

    $result = (string) $this->tool->handle(new Request([
        'action' => 'create',
        'name' => 'New Digest',
        'schedule' => '0 8 * * *',
        'prompt' => 'Send new digest.',
        'delivery_channel' => 'telegram',
    ]));

    expect($result)->toContain('Automation created')
        ->and(ProactiveTask::query()->count())->toBe(2);
});

it('bulk deletes tasks by comma-separated IDs', function () {
    $t1 = ProactiveTask::query()->create(['name' => 'Task 1', 'schedule' => '0 8 * * *', 'prompt' => 'Do 1.']);
    $t2 = ProactiveTask::query()->create(['name' => 'Task 2', 'schedule' => '0 9 * * *', 'prompt' => 'Do 2.']);
    $t3 = ProactiveTask::query()->create(['name' => 'Task 3', 'schedule' => '0 10 * * *', 'prompt' => 'Do 3.']);

    $result = (string) $this->tool->handle(new Request([
        'action' => 'delete',
        'task_ids' => "{$t1->id},{$t2->id}",
    ]));

    expect($result)->toContain('Deleted 2 automation(s)')
        ->and(ProactiveTask::query()->count())->toBe(1)
        ->and(ProactiveTask::query()->first()->name)->toBe('Task 3');
});

it('bulk deletes all tasks', function () {
    ProactiveTask::query()->create(['name' => 'Task 1', 'schedule' => '0 8 * * *', 'prompt' => 'Do 1.']);
    ProactiveTask::query()->create(['name' => 'Task 2', 'schedule' => '0 9 * * *', 'prompt' => 'Do 2.']);

    $result = (string) $this->tool->handle(new Request([
        'action' => 'delete',
        'task_ids' => 'all',
    ]));

    expect($result)->toContain('Deleted 2 automation(s)')
        ->and(ProactiveTask::query()->count())->toBe(0);
});

it('bulk toggles multiple tasks', function () {
    $t1 = ProactiveTask::query()->create(['name' => 'Active 1', 'schedule' => '0 8 * * *', 'prompt' => 'Do.', 'is_active' => true]);
    $t2 = ProactiveTask::query()->create(['name' => 'Active 2', 'schedule' => '0 9 * * *', 'prompt' => 'Do.', 'is_active' => true]);

    $result = (string) $this->tool->handle(new Request([
        'action' => 'toggle',
        'task_ids' => "{$t1->id},{$t2->id}",
    ]));

    expect($result)->toContain('Toggled 2 automation(s)')
        ->and($result)->toContain('paused');

    $t1->refresh();
    $t2->refresh();
    expect($t1->is_active)->toBeFalse()
        ->and($t2->is_active)->toBeFalse();
});

it('is auto-discovered by the tool registry', function () {
    $registry = app(\App\Tools\ToolRegistry::class);

    expect($registry->get('manage_automation'))->toBeInstanceOf(ProactiveTaskTool::class);
});
