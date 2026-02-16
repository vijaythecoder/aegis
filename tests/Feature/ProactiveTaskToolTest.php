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

    expect($result)->toContain('no task found with ID 999');
});

it('is auto-discovered by the tool registry', function () {
    $registry = app(\App\Tools\ToolRegistry::class);

    expect($registry->get('manage_automation'))->toBeInstanceOf(ProactiveTaskTool::class);
});
