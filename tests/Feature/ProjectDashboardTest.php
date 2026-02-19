<?php

use App\Livewire\ProjectDashboard;
use App\Models\Project;
use App\Models\ProjectKnowledge;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders the project dashboard page via route', function () {
    $project = Project::factory()->create();

    $this->get(route('project.dashboard', $project->id))
        ->assertStatus(200)
        ->assertSee($project->title);
});

it('renders project dashboard component with project data', function () {
    $project = Project::factory()->create([
        'title' => 'Tax Preparation',
        'category' => 'finance',
        'status' => 'active',
    ]);

    Livewire::test(ProjectDashboard::class, ['projectId' => $project->id])
        ->assertStatus(200)
        ->assertSee('Tax Preparation')
        ->assertSee('finance')
        ->assertSee('active');
});

it('displays tasks belonging to the project', function () {
    $project = Project::factory()->create();
    Task::factory()->create(['project_id' => $project->id, 'title' => 'Gather documents']);
    Task::factory()->create(['project_id' => $project->id, 'title' => 'File taxes']);

    Livewire::test(ProjectDashboard::class, ['projectId' => $project->id])
        ->assertSee('Gather documents')
        ->assertSee('File taxes');
});

it('shows progress bar with correct percentage', function () {
    $project = Project::factory()->create();
    Task::factory()->count(2)->create(['project_id' => $project->id, 'status' => 'completed', 'completed_at' => now()]);
    Task::factory()->count(2)->create(['project_id' => $project->id, 'status' => 'pending']);

    Livewire::test(ProjectDashboard::class, ['projectId' => $project->id])
        ->assertSee('50%')
        ->assertSee('2/4 tasks done');
});

it('creates a new task', function () {
    $project = Project::factory()->create();

    Livewire::test(ProjectDashboard::class, ['projectId' => $project->id])
        ->set('newTaskTitle', 'Research schools')
        ->set('newTaskPriority', 'high')
        ->call('createTask')
        ->assertSee('Task added');

    $task = Task::query()->where('title', 'Research schools')->first();
    expect($task)->not->toBeNull()
        ->and($task->project_id)->toBe($project->id)
        ->and($task->priority)->toBe('high')
        ->and($task->status)->toBe('pending');
});

it('validates task title is required', function () {
    $project = Project::factory()->create();

    Livewire::test(ProjectDashboard::class, ['projectId' => $project->id])
        ->set('newTaskTitle', '')
        ->call('createTask')
        ->assertHasErrors(['newTaskTitle']);
});

it('completes a task', function () {
    $project = Project::factory()->create();
    $task = Task::factory()->create([
        'project_id' => $project->id,
        'title' => 'Do something',
        'status' => 'pending',
    ]);

    Livewire::test(ProjectDashboard::class, ['projectId' => $project->id])
        ->call('completeTask', $task->id)
        ->assertSee('completed');

    $task->refresh();
    expect($task->status)->toBe('completed')
        ->and($task->completed_at)->not->toBeNull();
});

it('updates task status to in_progress', function () {
    $project = Project::factory()->create();
    $task = Task::factory()->create([
        'project_id' => $project->id,
        'status' => 'pending',
    ]);

    Livewire::test(ProjectDashboard::class, ['projectId' => $project->id])
        ->call('updateTaskStatus', $task->id, 'in_progress');

    expect($task->fresh()->status)->toBe('in_progress');
});

it('deletes a task', function () {
    $project = Project::factory()->create();
    $task = Task::factory()->create([
        'project_id' => $project->id,
        'title' => 'Remove me',
    ]);

    Livewire::test(ProjectDashboard::class, ['projectId' => $project->id])
        ->call('deleteTask', $task->id)
        ->assertSee('deleted');

    expect(Task::query()->find($task->id))->toBeNull();
});

it('updates project details', function () {
    $project = Project::factory()->create([
        'title' => 'Old Title',
        'status' => 'active',
    ]);

    Livewire::test(ProjectDashboard::class, ['projectId' => $project->id])
        ->set('projectTitle', 'New Title')
        ->set('projectStatus', 'paused')
        ->set('projectCategory', 'health')
        ->call('updateProject')
        ->assertSee('Project updated');

    $project->refresh();
    expect($project->title)->toBe('New Title')
        ->and($project->status)->toBe('paused')
        ->and($project->category)->toBe('health');
});

it('validates project title on update', function () {
    $project = Project::factory()->create();

    Livewire::test(ProjectDashboard::class, ['projectId' => $project->id])
        ->set('projectTitle', '')
        ->call('updateProject')
        ->assertHasErrors(['projectTitle']);
});

it('filters tasks by status', function () {
    $project = Project::factory()->create();
    Task::factory()->create(['project_id' => $project->id, 'title' => 'Pending Task', 'status' => 'pending']);
    Task::factory()->create(['project_id' => $project->id, 'title' => 'Done Task', 'status' => 'completed', 'completed_at' => now()]);

    Livewire::test(ProjectDashboard::class, ['projectId' => $project->id])
        ->set('statusFilter', 'pending')
        ->assertSee('Pending Task')
        ->assertDontSee('Done Task');
});

it('shows project knowledge when present', function () {
    $project = Project::factory()->create();
    ProjectKnowledge::query()->create([
        'project_id' => $project->id,
        'key' => 'Budget',
        'value' => '$15,000 allocated',
    ]);

    Livewire::test(ProjectDashboard::class, ['projectId' => $project->id])
        ->assertSee('Budget')
        ->assertSee('$15,000 allocated');
});

it('returns 404 for non-existent project', function () {
    $this->get(route('project.dashboard', 999))
        ->assertStatus(404);
});
