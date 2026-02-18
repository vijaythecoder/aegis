<?php

use App\Models\Project;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a task with factory', function () {
    $task = Task::factory()->create();

    expect($task)->toBeInstanceOf(Task::class);
    expect($task->title)->not->toBeEmpty();
    expect($task->status)->toBe('pending');
});

it('belongs to a project', function () {
    $project = Project::factory()->create();
    $task = Task::factory()->for($project)->create();

    expect($task->project)->toBeInstanceOf(Project::class);
    expect($task->project->id)->toBe($project->id);
});

it('has many subtasks', function () {
    $parentTask = Task::factory()->create();
    $subtasks = Task::factory(2)->create([
        'parent_task_id' => $parentTask->id,
    ]);

    expect($parentTask->subtasks)->toHaveCount(2);
    expect($parentTask->subtasks->first())->toBeInstanceOf(Task::class);
});

it('belongs to a parent task', function () {
    $parentTask = Task::factory()->create();
    $childTask = Task::factory()->create([
        'parent_task_id' => $parentTask->id,
    ]);

    expect($childTask->parent)->toBeInstanceOf(Task::class);
    expect($childTask->parent->id)->toBe($parentTask->id);
});

it('scopes pending tasks', function () {
    Task::factory(2)->pending()->create();
    Task::factory(1)->completed()->create();

    expect(Task::pending()->count())->toBe(2);
});

it('scopes tasks for agent', function () {
    Task::factory(2)->assignedToAgent()->create();
    Task::factory(1)->assignedToUser()->create();

    expect(Task::forAgent(1)->count())->toBe(2);
});

it('casts deadline to datetime', function () {
    $deadline = now()->addDays(7);
    $task = Task::factory()->create([
        'deadline' => $deadline,
    ]);

    expect($task->deadline)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

it('casts completed_at to datetime', function () {
    $task = Task::factory()->completed()->create();

    expect($task->completed_at)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});

it('can be assigned to agent', function () {
    $task = Task::factory()->assignedToAgent()->create();

    expect($task->assigned_type)->toBe('agent');
    expect($task->assigned_id)->toBe(1);
});

it('can be assigned to user', function () {
    $task = Task::factory()->assignedToUser()->create();

    expect($task->assigned_type)->toBe('user');
});
