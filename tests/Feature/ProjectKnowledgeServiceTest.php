<?php

use App\Models\Project;
use App\Models\ProjectKnowledge;
use App\Models\Task;
use App\Services\ProjectKnowledgeService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores project knowledge', function () {
    $project = Project::factory()->create();

    $service = app(ProjectKnowledgeService::class);
    $knowledge = $service->store($project->id, 'research_findings', 'Found 3 options', 'artifact');

    expect($knowledge)->toBeInstanceOf(ProjectKnowledge::class)
        ->and($knowledge->project_id)->toBe($project->id)
        ->and($knowledge->key)->toBe('research_findings')
        ->and($knowledge->value)->toBe('Found 3 options')
        ->and($knowledge->type)->toBe('artifact');
});

it('stores knowledge with task association', function () {
    $project = Project::factory()->create();
    $task = Task::factory()->create(['project_id' => $project->id]);

    $service = app(ProjectKnowledgeService::class);
    $knowledge = $service->store($project->id, 'task_output', 'Completed research', 'artifact', $task->id);

    expect($knowledge->task_id)->toBe($task->id);
});

it('defaults type to note', function () {
    $project = Project::factory()->create();

    $service = app(ProjectKnowledgeService::class);
    $knowledge = $service->store($project->id, 'my_note', 'Some content');

    expect($knowledge->type)->toBe('note');
});

it('gets all knowledge for a project', function () {
    $project = Project::factory()->create();
    $other = Project::factory()->create();

    ProjectKnowledge::factory()->count(3)->create(['project_id' => $project->id]);
    ProjectKnowledge::factory()->count(2)->create(['project_id' => $other->id]);

    $service = app(ProjectKnowledgeService::class);
    $result = $service->getForProject($project->id);

    expect($result)->toHaveCount(3)
        ->and($result->every(fn ($k) => $k->project_id === $project->id))->toBeTrue();
});

it('searches knowledge by key and value', function () {
    $project = Project::factory()->create();

    ProjectKnowledge::factory()->create([
        'project_id' => $project->id,
        'key' => 'school_research',
        'value' => 'Found 5 schools nearby',
    ]);
    ProjectKnowledge::factory()->create([
        'project_id' => $project->id,
        'key' => 'budget',
        'value' => 'Max 50000 per year',
    ]);
    ProjectKnowledge::factory()->create([
        'project_id' => $project->id,
        'key' => 'timeline',
        'value' => 'Apply by March',
    ]);

    $service = app(ProjectKnowledgeService::class);

    $byKey = $service->search($project->id, 'school');
    expect($byKey)->toHaveCount(1)
        ->and($byKey->first()->key)->toBe('school_research');

    $byValue = $service->search($project->id, 'March');
    expect($byValue)->toHaveCount(1)
        ->and($byValue->first()->key)->toBe('timeline');
});

it('search does not return results from other projects', function () {
    $project = Project::factory()->create();
    $other = Project::factory()->create();

    ProjectKnowledge::factory()->create([
        'project_id' => $other->id,
        'key' => 'secret',
        'value' => 'Should not appear',
    ]);

    $service = app(ProjectKnowledgeService::class);
    $result = $service->search($project->id, 'secret');

    expect($result)->toBeEmpty();
});

it('summarizes project knowledge', function () {
    $project = Project::factory()->create();

    ProjectKnowledge::factory()->create([
        'project_id' => $project->id,
        'key' => 'findings',
        'value' => 'Three viable options identified',
    ]);
    ProjectKnowledge::factory()->create([
        'project_id' => $project->id,
        'key' => 'budget_estimate',
        'value' => 'Approximately 25000 USD',
    ]);

    $service = app(ProjectKnowledgeService::class);
    $summary = $service->summarize($project->id);

    expect($summary)->toContain('findings')
        ->and($summary)->toContain('budget_estimate')
        ->and($summary)->toContain('Three viable options');
});

it('returns empty string when summarizing project with no knowledge', function () {
    $project = Project::factory()->create();

    $service = app(ProjectKnowledgeService::class);
    $summary = $service->summarize($project->id);

    expect($summary)->toBe('');
});

it('truncates long values in summary', function () {
    $project = Project::factory()->create();

    ProjectKnowledge::factory()->create([
        'project_id' => $project->id,
        'key' => 'long_content',
        'value' => str_repeat('A', 300),
    ]);

    $service = app(ProjectKnowledgeService::class);
    $summary = $service->summarize($project->id);

    expect($summary)->toContain('...')
        ->and(mb_strlen($summary))->toBeLessThan(300);
});

it('limits summary to 10 entries', function () {
    $project = Project::factory()->create();

    ProjectKnowledge::factory()->count(15)->create(['project_id' => $project->id]);

    $service = app(ProjectKnowledgeService::class);
    $summary = $service->summarize($project->id);

    $lines = array_filter(explode("\n", $summary));
    expect(count($lines))->toBeLessThanOrEqual(10);
});
