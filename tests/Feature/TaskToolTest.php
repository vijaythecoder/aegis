<?php

use App\Models\Agent;
use App\Models\Project;
use App\Models\Task;
use App\Tools\TaskTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tool = app(TaskTool::class);
});

it('implements the SDK Tool contract', function () {
    expect($this->tool)->toBeInstanceOf(\Laravel\Ai\Contracts\Tool::class);
});

it('has correct name and description', function () {
    expect($this->tool->name())->toBe('manage_tasks')
        ->and((string) $this->tool->description())->toContain('task');
});

it('creates a task with title, project_id, and priority', function () {
    $project = Project::factory()->create(['title' => 'Tax Prep']);

    $result = (string) $this->tool->handle(new Request([
        'action' => 'create',
        'title' => 'Gather W-2s',
        'project_id' => $project->id,
        'priority' => 'high',
    ]));

    expect($result)->toContain('Task created')
        ->and($result)->toContain('Gather W-2s')
        ->and($result)->toContain("project ID:{$project->id}")
        ->and($result)->toContain('[high]');

    $task = Task::query()->where('title', 'Gather W-2s')->first();
    expect($task)->not->toBeNull()
        ->and($task->project_id)->toBe($project->id)
        ->and($task->priority)->toBe('high')
        ->and($task->status)->toBe('pending')
        ->and($task->assigned_type)->toBe('user');
});

it('creates a standalone task without a project', function () {
    $result = (string) $this->tool->handle(new Request([
        'action' => 'create',
        'title' => 'Buy groceries',
    ]));

    expect($result)->toContain('Task created')
        ->and($result)->toContain('Buy groceries');

    $task = Task::query()->where('title', 'Buy groceries')->first();
    expect($task)->not->toBeNull()
        ->and($task->project_id)->toBeNull()
        ->and($task->priority)->toBe('medium');
});

it('rejects create when title is missing', function () {
    $result = (string) $this->tool->handle(new Request([
        'action' => 'create',
        'project_id' => 1,
    ]));

    expect($result)->toContain('title is required');
    expect(Task::query()->count())->toBe(0);
});

it('rejects create with non-existent project_id', function () {
    $result = (string) $this->tool->handle(new Request([
        'action' => 'create',
        'title' => 'Orphan task',
        'project_id' => 999,
    ]));

    expect($result)->toContain('no project found with ID 999');
    expect(Task::query()->count())->toBe(0);
});

it('assigns task to an agent by slug on create', function () {
    $agent = Agent::factory()->create(['name' => 'FitCoach', 'slug' => 'fitcoach', 'is_active' => true]);

    $result = (string) $this->tool->handle(new Request([
        'action' => 'create',
        'title' => 'Create workout plan',
        'assigned_type' => 'agent',
        'assigned_id' => 'fitcoach',
    ]));

    expect($result)->toContain('Task created')
        ->and($result)->toContain('assigned to FitCoach');

    $task = Task::query()->where('title', 'Create workout plan')->first();
    expect($task->assigned_type)->toBe('agent')
        ->and($task->assigned_id)->toBe($agent->id);
});

it('rejects agent assignment with invalid slug', function () {
    $result = (string) $this->tool->handle(new Request([
        'action' => 'create',
        'title' => 'Bad assign',
        'assigned_type' => 'agent',
        'assigned_id' => 'nonexistent',
    ]));

    expect($result)->toContain('no active agent found with slug "nonexistent"');
    expect(Task::query()->count())->toBe(0);
});

it('rejects agent assignment when slug is empty', function () {
    $result = (string) $this->tool->handle(new Request([
        'action' => 'create',
        'title' => 'No slug',
        'assigned_type' => 'agent',
    ]));

    expect($result)->toContain('assigned_id (agent slug) is required');
    expect(Task::query()->count())->toBe(0);
});

it('lists tasks with filters', function () {
    $project = Project::factory()->create();
    Task::factory()->create(['project_id' => $project->id, 'title' => 'Pending One', 'status' => 'pending']);
    Task::factory()->create(['project_id' => $project->id, 'title' => 'Done One', 'status' => 'completed']);
    Task::factory()->create(['title' => 'Other Project Task', 'status' => 'pending']);

    $result = (string) $this->tool->handle(new Request([
        'action' => 'list',
        'project_id' => $project->id,
    ]));

    expect($result)->toContain('Pending One')
        ->and($result)->toContain('Done One')
        ->and($result)->not->toContain('Other Project Task');
});

it('lists tasks filtered by status', function () {
    Task::factory()->create(['title' => 'Active Task', 'status' => 'pending']);
    Task::factory()->create(['title' => 'Finished Task', 'status' => 'completed']);

    $result = (string) $this->tool->handle(new Request([
        'action' => 'list',
        'status' => 'pending',
    ]));

    expect($result)->toContain('Active Task')
        ->and($result)->not->toContain('Finished Task');
});

it('lists tasks filtered by assigned_type', function () {
    $agent = Agent::factory()->create(['is_active' => true]);
    Task::factory()->create(['title' => 'User Task', 'assigned_type' => 'user']);
    Task::factory()->create(['title' => 'Agent Task', 'assigned_type' => 'agent', 'assigned_id' => $agent->id]);

    $result = (string) $this->tool->handle(new Request([
        'action' => 'list',
        'assigned_type' => 'agent',
    ]));

    expect($result)->toContain('Agent Task')
        ->and($result)->not->toContain('User Task');
});

it('returns message when no tasks match filters', function () {
    $result = (string) $this->tool->handle(new Request([
        'action' => 'list',
        'status' => 'in_progress',
    ]));

    expect($result)->toContain('No tasks found');
});

it('updates task fields', function () {
    $task = Task::factory()->create(['title' => 'Old Title', 'priority' => 'low']);

    $result = (string) $this->tool->handle(new Request([
        'action' => 'update',
        'task_id' => $task->id,
        'title' => 'New Title',
        'priority' => 'high',
    ]));

    expect($result)->toContain('updated successfully');

    $task->refresh();
    expect($task->title)->toBe('New Title')
        ->and($task->priority)->toBe('high');
});

it('sets completed_at when status updated to completed', function () {
    $task = Task::factory()->create(['status' => 'pending']);

    $this->tool->handle(new Request([
        'action' => 'update',
        'task_id' => $task->id,
        'status' => 'completed',
    ]));

    $task->refresh();
    expect($task->status)->toBe('completed')
        ->and($task->completed_at)->not->toBeNull();
});

it('rejects update without task_id', function () {
    $result = (string) $this->tool->handle(new Request([
        'action' => 'update',
        'title' => 'Should Fail',
    ]));

    expect($result)->toContain('task_id is required');
});

it('rejects update for non-existent task', function () {
    $result = (string) $this->tool->handle(new Request([
        'action' => 'update',
        'task_id' => 999,
        'title' => 'Should Fail',
    ]));

    expect($result)->toContain('no task found with ID 999');
});

it('completes a task with output', function () {
    $task = Task::factory()->create(['title' => 'Research', 'status' => 'pending']);

    $result = (string) $this->tool->handle(new Request([
        'action' => 'complete',
        'task_id' => $task->id,
        'output' => 'Found 3 schools within budget',
    ]));

    expect($result)->toContain('marked as completed')
        ->and($result)->toContain('Output recorded');

    $task->refresh();
    expect($task->status)->toBe('completed')
        ->and($task->completed_at)->not->toBeNull()
        ->and($task->output)->toBe('Found 3 schools within budget');
});

it('completes a task without output', function () {
    $task = Task::factory()->create(['title' => 'Simple', 'status' => 'pending']);

    $result = (string) $this->tool->handle(new Request([
        'action' => 'complete',
        'task_id' => $task->id,
    ]));

    expect($result)->toContain('marked as completed')
        ->and($result)->not->toContain('Output recorded');
});

it('assigns a task to an agent', function () {
    $agent = Agent::factory()->create(['name' => 'ResearchBot', 'slug' => 'researchbot', 'is_active' => true]);
    $task = Task::factory()->create(['title' => 'Investigate', 'assigned_type' => 'user']);

    $result = (string) $this->tool->handle(new Request([
        'action' => 'assign',
        'task_id' => $task->id,
        'assigned_type' => 'agent',
        'assigned_id' => 'researchbot',
    ]));

    expect($result)->toContain('assigned to ResearchBot');

    $task->refresh();
    expect($task->assigned_type)->toBe('agent')
        ->and($task->assigned_id)->toBe($agent->id);
});

it('reassigns a task back to user', function () {
    $agent = Agent::factory()->create(['is_active' => true]);
    $task = Task::factory()->create(['assigned_type' => 'agent', 'assigned_id' => $agent->id]);

    $result = (string) $this->tool->handle(new Request([
        'action' => 'assign',
        'task_id' => $task->id,
        'assigned_type' => 'user',
    ]));

    expect($result)->toContain('assigned to you');

    $task->refresh();
    expect($task->assigned_type)->toBe('user')
        ->and($task->assigned_id)->toBeNull();
});

it('rejects assign with invalid agent slug', function () {
    $task = Task::factory()->create();

    $result = (string) $this->tool->handle(new Request([
        'action' => 'assign',
        'task_id' => $task->id,
        'assigned_type' => 'agent',
        'assigned_id' => 'ghostagent',
    ]));

    expect($result)->toContain('no active agent found with slug "ghostagent"');
});

it('handles unknown action gracefully', function () {
    $result = (string) $this->tool->handle(new Request([
        'action' => 'purge',
    ]));

    expect($result)->toContain('Unknown action');
});
