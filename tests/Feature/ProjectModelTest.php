<?php

use App\Models\Project;
use App\Models\ProjectKnowledge;
use App\Models\Task;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a project with factory', function () {
    $project = Project::factory()->create();

    expect($project)->toBeInstanceOf(Project::class);
    expect($project->title)->not->toBeEmpty();
    expect($project->status)->toBe('active');
});

it('has many tasks', function () {
    $project = Project::factory()->create();
    $tasks = Task::factory(3)->for($project)->create();

    expect($project->tasks)->toHaveCount(3);
    expect($project->tasks->first())->toBeInstanceOf(Task::class);
});

it('has many knowledge entries', function () {
    $project = Project::factory()->create();
    $knowledge = ProjectKnowledge::factory(2)->for($project)->create();

    expect($project->knowledge)->toHaveCount(2);
    expect($project->knowledge->first())->toBeInstanceOf(ProjectKnowledge::class);
});

it('scopes active projects', function () {
    Project::factory(2)->active()->create();
    Project::factory(1)->completed()->create();

    expect(Project::active()->count())->toBe(2);
});

it('scopes completed projects', function () {
    Project::factory(2)->active()->create();
    Project::factory(1)->completed()->create();

    expect(Project::completed()->count())->toBe(1);
});

it('casts metadata to array', function () {
    $project = Project::factory()->create([
        'metadata' => ['key' => 'value'],
    ]);

    expect($project->metadata)->toBeArray();
    expect($project->metadata['key'])->toBe('value');
});

it('casts deadline to datetime', function () {
    $deadline = now()->addDays(7);
    $project = Project::factory()->create([
        'deadline' => $deadline,
    ]);

    expect($project->deadline)->toBeInstanceOf(\Illuminate\Support\Carbon::class);
});
