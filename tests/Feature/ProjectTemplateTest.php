<?php

use App\Models\Project;
use App\Models\ProjectTemplate;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a project from a template with tasks', function () {
    $template = ProjectTemplate::factory()->create([
        'name' => 'Tax Prep',
        'slug' => 'tax-prep',
        'category' => 'finance',
        'tasks' => [
            ['title' => 'Gather W-2s', 'description' => 'Collect wage statements', 'assigned_type' => 'user', 'priority' => 'high', 'order' => 1],
            ['title' => 'Organize receipts', 'description' => 'Sort deductible expenses', 'assigned_type' => 'user', 'priority' => 'medium', 'order' => 2],
            ['title' => 'File return', 'description' => null, 'assigned_type' => 'agent', 'priority' => 'high', 'order' => 3],
        ],
    ]);

    $project = Project::fromTemplate($template);

    expect($project->title)->toBe('Tax Prep')
        ->and($project->category)->toBe('finance')
        ->and($project->status)->toBe('active')
        ->and($project->tasks)->toHaveCount(3);

    $first = $project->tasks->first();
    expect($first->title)->toBe('Gather W-2s')
        ->and($first->description)->toBe('Collect wage statements')
        ->and($first->priority)->toBe('high')
        ->and($first->status)->toBe('pending');
});

it('allows overrides when creating from template', function () {
    $template = ProjectTemplate::factory()->create([
        'name' => 'Learning Goal',
        'tasks' => [
            ['title' => 'Step 1', 'description' => null, 'assigned_type' => 'user', 'priority' => 'medium', 'order' => 1],
        ],
    ]);

    $deadline = now()->addMonth();
    $project = Project::fromTemplate($template, [
        'title' => 'Learn Rust',
        'deadline' => $deadline,
    ]);

    expect($project->title)->toBe('Learn Rust')
        ->and($project->deadline->toDateString())->toBe($deadline->toDateString());
});

it('creates template with built-in flag', function () {
    $template = ProjectTemplate::factory()->builtIn()->create();

    expect($template->is_built_in)->toBeTrue();
});

it('enforces unique slug on templates', function () {
    ProjectTemplate::factory()->create(['slug' => 'my-template']);

    expect(fn () => ProjectTemplate::factory()->create(['slug' => 'my-template']))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

it('casts tasks as array', function () {
    $template = ProjectTemplate::factory()->create([
        'tasks' => [
            ['title' => 'A', 'description' => 'test', 'assigned_type' => 'user', 'priority' => 'low', 'order' => 1],
        ],
    ]);

    $template->refresh();

    expect($template->tasks)->toBeArray()
        ->and($template->tasks[0]['title'])->toBe('A');
});

it('seeds built-in templates via seeder', function () {
    $this->seed(\Database\Seeders\ProjectTemplateSeeder::class);

    expect(ProjectTemplate::query()->count())->toBe(4);
    expect(ProjectTemplate::query()->where('is_built_in', true)->count())->toBe(4);
    expect(ProjectTemplate::query()->where('slug', 'tax-preparation')->exists())->toBeTrue();
    expect(ProjectTemplate::query()->where('slug', 'home-project')->exists())->toBeTrue();
});

it('seeder is idempotent', function () {
    $this->seed(\Database\Seeders\ProjectTemplateSeeder::class);
    $this->seed(\Database\Seeders\ProjectTemplateSeeder::class);

    expect(ProjectTemplate::query()->count())->toBe(4);
});
