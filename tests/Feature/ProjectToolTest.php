<?php

use App\Models\Project;
use App\Models\Task;
use App\Tools\ProjectTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tool = app(ProjectTool::class);
});

it('implements the SDK Tool contract', function () {
    expect($this->tool)->toBeInstanceOf(\Laravel\Ai\Contracts\Tool::class);
});

it('has correct name and description', function () {
    expect($this->tool->name())->toBe('manage_projects')
        ->and((string) $this->tool->description())->toContain('project');
});

it('creates a project with title, description, category, and deadline', function () {
    $result = (string) $this->tool->handle(new Request([
        'action' => 'create',
        'title' => 'Tax Preparation',
        'description' => 'Gather docs and file 2025 taxes',
        'category' => 'finance',
        'deadline' => '2026-04-15',
    ]));

    expect($result)->toContain('Project created')
        ->and($result)->toContain('Tax Preparation')
        ->and($result)->toContain('[finance]')
        ->and($result)->toContain('Apr 15, 2026');

    $project = Project::query()->where('title', 'Tax Preparation')->first();
    expect($project)->not->toBeNull()
        ->and($project->status)->toBe('active')
        ->and($project->category)->toBe('finance')
        ->and($project->description)->toBe('Gather docs and file 2025 taxes')
        ->and($project->deadline->format('Y-m-d'))->toBe('2026-04-15');
});

it('creates a minimal project with only title', function () {
    $result = (string) $this->tool->handle(new Request([
        'action' => 'create',
        'title' => 'Quick Project',
    ]));

    expect($result)->toContain('Project created')
        ->and($result)->toContain('Quick Project');

    $project = Project::query()->where('title', 'Quick Project')->first();
    expect($project)->not->toBeNull()
        ->and($project->status)->toBe('active')
        ->and($project->category)->toBeNull()
        ->and($project->deadline)->toBeNull();
});

it('rejects create when title is missing', function () {
    $result = (string) $this->tool->handle(new Request([
        'action' => 'create',
        'description' => 'No title provided',
    ]));

    expect($result)->toContain('title is required');
    expect(Project::query()->count())->toBe(0);
});

it('lists projects with task counts', function () {
    $project = Project::factory()->create(['title' => 'Home Reno']);
    Task::factory()->count(3)->create(['project_id' => $project->id, 'status' => 'pending']);
    Task::factory()->count(2)->create(['project_id' => $project->id, 'status' => 'completed']);

    $result = (string) $this->tool->handle(new Request([
        'action' => 'list',
    ]));

    expect($result)->toContain('Home Reno')
        ->and($result)->toContain('2/5 tasks done')
        ->and($result)->toContain('3 pending');
});

it('lists projects filtered by status', function () {
    Project::factory()->create(['title' => 'Active One', 'status' => 'active']);
    Project::factory()->create(['title' => 'Archived One', 'status' => 'archived']);

    $result = (string) $this->tool->handle(new Request([
        'action' => 'list',
        'status' => 'active',
    ]));

    expect($result)->toContain('Active One')
        ->and($result)->not->toContain('Archived One');
});

it('returns message when no projects found', function () {
    $result = (string) $this->tool->handle(new Request([
        'action' => 'list',
        'status' => 'completed',
    ]));

    expect($result)->toContain('No projects found')
        ->and($result)->toContain('completed');
});

it('updates project fields', function () {
    $project = Project::factory()->create(['title' => 'Old Title', 'status' => 'active']);

    $result = (string) $this->tool->handle(new Request([
        'action' => 'update',
        'project_id' => $project->id,
        'title' => 'New Title',
        'status' => 'paused',
        'category' => 'health',
    ]));

    expect($result)->toContain('updated successfully');

    $project->refresh();
    expect($project->title)->toBe('New Title')
        ->and($project->status)->toBe('paused')
        ->and($project->category)->toBe('health');
});

it('rejects update without project_id', function () {
    $result = (string) $this->tool->handle(new Request([
        'action' => 'update',
        'title' => 'Should Fail',
    ]));

    expect($result)->toContain('project_id is required');
});

it('rejects update for non-existent project', function () {
    $result = (string) $this->tool->handle(new Request([
        'action' => 'update',
        'project_id' => 999,
        'title' => 'Should Fail',
    ]));

    expect($result)->toContain('no project found with ID 999');
});

it('rejects update with no changes provided', function () {
    $project = Project::factory()->create();

    $result = (string) $this->tool->handle(new Request([
        'action' => 'update',
        'project_id' => $project->id,
    ]));

    expect($result)->toContain('no changes provided');
});

it('archives a project', function () {
    $project = Project::factory()->create(['title' => 'To Archive', 'status' => 'active']);

    $result = (string) $this->tool->handle(new Request([
        'action' => 'archive',
        'project_id' => $project->id,
    ]));

    expect($result)->toContain('archived')
        ->and($result)->toContain('To Archive');

    $project->refresh();
    expect($project->status)->toBe('archived');
});

it('rejects archive without project_id', function () {
    $result = (string) $this->tool->handle(new Request([
        'action' => 'archive',
    ]));

    expect($result)->toContain('project_id is required');
});

it('gets project detail with tasks and knowledge', function () {
    $project = Project::factory()->create([
        'title' => 'Kid Education',
        'description' => 'Plan schooling for 2026',
        'category' => 'education',
        'deadline' => '2026-08-01',
    ]);

    Task::factory()->create(['project_id' => $project->id, 'title' => 'Research schools', 'status' => 'completed']);
    Task::factory()->create(['project_id' => $project->id, 'title' => 'Schedule visits', 'status' => 'pending']);

    $project->knowledge()->create(['key' => 'Budget', 'value' => '$20,000']);

    $result = (string) $this->tool->handle(new Request([
        'action' => 'get',
        'project_id' => $project->id,
    ]));

    expect($result)->toContain('Kid Education')
        ->and($result)->toContain('Plan schooling for 2026')
        ->and($result)->toContain('education')
        ->and($result)->toContain('Aug 1, 2026')
        ->and($result)->toContain('1/2 tasks completed')
        ->and($result)->toContain('Research schools')
        ->and($result)->toContain('Schedule visits')
        ->and($result)->toContain('Budget')
        ->and($result)->toContain('$20,000');
});

it('returns error when get is called without project_id', function () {
    $result = (string) $this->tool->handle(new Request([
        'action' => 'get',
    ]));

    expect($result)->toContain('project_id is required');
});

it('returns error when get is called with non-existent project', function () {
    $result = (string) $this->tool->handle(new Request([
        'action' => 'get',
        'project_id' => 999,
    ]));

    expect($result)->toContain('No project found with ID 999');
});

it('handles unknown action gracefully', function () {
    $result = (string) $this->tool->handle(new Request([
        'action' => 'delete',
    ]));

    expect($result)->toContain('Unknown action');
});
