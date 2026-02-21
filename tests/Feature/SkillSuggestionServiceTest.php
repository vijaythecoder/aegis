<?php

use App\Models\Project;
use App\Models\Skill;
use App\Services\SkillSuggestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = new SkillSuggestionService;
    $this->seed(\Database\Seeders\SkillSeeder::class);
});

it('suggests health-fitness skill for fitness persona', function () {
    $suggestions = $this->service->suggestForPersona('I want a fitness coach who helps with workouts');

    expect($suggestions)->not->toBeEmpty()
        ->and($suggestions->pluck('slug')->toArray())->toContain('health-fitness');
});

it('suggests finance-tracker skill for tax-related persona', function () {
    $suggestions = $this->service->suggestForPersona('Help me with my taxes and budget');

    expect($suggestions)->not->toBeEmpty()
        ->and($suggestions->pluck('slug')->toArray())->toContain('finance-tracker');
});

it('suggests writing-coach for content creation persona', function () {
    $suggestions = $this->service->suggestForPersona('I need help writing blog articles and editing drafts');

    $slugs = $suggestions->pluck('slug')->toArray();
    expect($slugs)->toContain('writing-coach');
});

it('suggests multiple skills when persona matches multiple domains', function () {
    $suggestions = $this->service->suggestForPersona('Help me research and write articles');

    $slugs = $suggestions->pluck('slug')->toArray();
    expect($slugs)->toContain('research-assistant')
        ->and($slugs)->toContain('writing-coach');
});

it('returns empty collection for unmatched persona', function () {
    $suggestions = $this->service->suggestForPersona('random buddy for hanging out');

    expect($suggestions)->toBeEmpty();
});

it('is case insensitive for persona matching', function () {
    $suggestions = $this->service->suggestForPersona('HELP ME WITH FITNESS AND EXERCISE');

    expect($suggestions)->not->toBeEmpty()
        ->and($suggestions->pluck('slug')->toArray())->toContain('health-fitness');
});

it('only returns active skills', function () {
    Skill::query()->where('slug', 'health-fitness')->update(['is_active' => false]);

    $suggestions = $this->service->suggestForPersona('fitness workout exercise');

    expect($suggestions)->toBeEmpty();
});

it('suggests skills for project by category', function () {
    $project = Project::factory()->create(['category' => 'finance']);

    $suggestions = $this->service->suggestForProject($project);

    $slugs = $suggestions->pluck('slug')->toArray();
    expect($slugs)->toContain('finance-tracker')
        ->and($slugs)->toContain('data-analyst');
});

it('suggests skills for education category project', function () {
    $project = Project::factory()->create(['category' => 'education']);

    $suggestions = $this->service->suggestForProject($project);

    $slugs = $suggestions->pluck('slug')->toArray();
    expect($slugs)->toContain('learning-guide')
        ->and($slugs)->toContain('research-assistant')
        ->and($slugs)->toContain('writing-coach');
});

it('falls back to persona matching from project title when category unknown', function () {
    $project = Project::factory()->create([
        'category' => null,
        'title' => 'Daily Workout Routine',
        'description' => 'Track my exercise and nutrition goals',
    ]);

    $suggestions = $this->service->suggestForProject($project);

    $slugs = $suggestions->pluck('slug')->toArray();
    expect($slugs)->toContain('health-fitness');
});

it('falls back to persona matching from description when category unknown', function () {
    $project = Project::factory()->create([
        'category' => null,
        'title' => 'Personal Project',
        'description' => 'Help me budget and track my finances',
    ]);

    $suggestions = $this->service->suggestForProject($project);

    $slugs = $suggestions->pluck('slug')->toArray();
    expect($slugs)->toContain('finance-tracker');
});

it('returns empty when project has no matching category or keywords', function () {
    $project = Project::factory()->create([
        'category' => 'misc',
        'title' => 'Stuff',
        'description' => 'Random things',
    ]);

    $suggestions = $this->service->suggestForProject($project);

    expect($suggestions)->toBeEmpty();
});
