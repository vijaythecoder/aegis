<?php

use App\Agent\SystemPromptBuilder;
use App\Enums\MemoryType;
use App\Memory\MemoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('builds system prompt with core sections', function () {
    $builder = app(SystemPromptBuilder::class);

    $prompt = $builder->build();

    expect($prompt)->toContain('Aegis')
        ->and($prompt)->toContain('Current datetime')
        ->and($prompt)->toContain('Available tools')
        ->and($prompt)->toContain('Memory System (Automatic)')
        ->and($prompt)->toContain('auto-recalled');
});

it('includes user profile when provided', function () {
    $builder = app(SystemPromptBuilder::class);

    $prompt = $builder->build(userProfile: 'Name: Vijay. Timezone: America/Chicago.');

    expect($prompt)->toContain('About This User')
        ->and($prompt)->toContain('Vijay')
        ->and($prompt)->toContain('America/Chicago');
});

it('omits user profile section when null', function () {
    $builder = app(SystemPromptBuilder::class);

    $prompt = $builder->build(userProfile: null);

    expect($prompt)->not->toContain('About This User');
});

it('includes facts from memory', function () {
    $memoryService = app(MemoryService::class);
    $memoryService->store(MemoryType::Fact, 'user.name', 'Vijay');
    $memoryService->store(MemoryType::Fact, 'user.timezone', 'America/Chicago');

    $builder = app(SystemPromptBuilder::class);
    $prompt = $builder->build();

    expect($prompt)->toContain('Known Facts (AUTHORITATIVE)')
        ->and($prompt)->toContain('user.name: Vijay')
        ->and($prompt)->toContain('user.timezone: America/Chicago');
});

it('includes preferences from memory', function () {
    $memoryService = app(MemoryService::class);
    $memoryService->store(MemoryType::Preference, 'theme', 'dark mode');

    $builder = app(SystemPromptBuilder::class);
    $prompt = $builder->build();

    expect($prompt)->toContain('User preferences')
        ->and($prompt)->toContain('theme: dark mode');
});

it('includes automatic memory system instructions', function () {
    $builder = app(SystemPromptBuilder::class);
    $prompt = $builder->build();

    expect($prompt)->toContain('Memory System (Automatic)')
        ->and($prompt)->toContain('auto-recalled')
        ->and($prompt)->toContain('automatically extracted')
        ->and($prompt)->not->toContain('MANDATORY: Before answering')
        ->and($prompt)->not->toContain('use the memory_store tool');
});

it('includes active procedures in system prompt', function () {
    \App\Models\Procedure::query()->create([
        'trigger' => 'user asks about code style',
        'instruction' => 'Always use const instead of var',
        'is_active' => true,
    ]);

    $builder = app(SystemPromptBuilder::class);
    $prompt = $builder->build();

    expect($prompt)->toContain('Learned Behaviors')
        ->and($prompt)->toContain('user asks about code style')
        ->and($prompt)->toContain('Always use const instead of var');
});

it('excludes inactive procedures from system prompt', function () {
    \App\Models\Procedure::query()->create([
        'trigger' => 'user asks about code style',
        'instruction' => 'Always use const',
        'is_active' => false,
    ]);

    $builder = app(SystemPromptBuilder::class);
    $prompt = $builder->build();

    expect($prompt)->not->toContain('Learned Behaviors');
});

it('omits procedures section when none exist', function () {
    $builder = app(SystemPromptBuilder::class);
    $prompt = $builder->build();

    expect($prompt)->not->toContain('Learned Behaviors');
});

it('does not include skills section when no agent model is provided', function () {
    $builder = app(SystemPromptBuilder::class);
    $prompt = $builder->build();
    expect($prompt)->not->toContain('Specialized Knowledge');
});

it('includes skills section when agent model with skills is provided', function () {
    $agent = \App\Models\Agent::factory()->create();
    $skill1 = \App\Models\Skill::factory()->create(['name' => 'Fitness Coach', 'instructions' => 'Help with workouts and nutrition.']);
    $skill2 = \App\Models\Skill::factory()->create(['name' => 'Research Expert', 'instructions' => 'Deep research capabilities.']);
    $agent->skills()->attach([$skill1->id, $skill2->id]);

    $builder = app(SystemPromptBuilder::class);
    $prompt = $builder->build(agentModel: $agent);
    expect($prompt)
        ->toContain('## Specialized Knowledge')
        ->toContain('### Fitness Coach')
        ->toContain('Help with workouts and nutrition.')
        ->toContain('### Research Expert')
        ->toContain('Deep research capabilities.');
});

it('skips inactive skills in skills section', function () {
    $agent = \App\Models\Agent::factory()->create();
    $active = \App\Models\Skill::factory()->create(['name' => 'Active Skill', 'instructions' => 'I am active.', 'is_active' => true]);
    $inactive = \App\Models\Skill::factory()->create(['name' => 'Inactive Skill', 'instructions' => 'I am inactive.', 'is_active' => false]);
    $agent->skills()->attach([$active->id, $inactive->id]);

    $builder = app(SystemPromptBuilder::class);
    $prompt = $builder->build(agentModel: $agent);
    expect($prompt)
        ->toContain('### Active Skill')
        ->not->toContain('### Inactive Skill');
});

it('skips skills with empty instructions', function () {
    $agent = \App\Models\Agent::factory()->create();
    $withContent = \App\Models\Skill::factory()->create(['name' => 'Has Content', 'instructions' => 'Real instructions here.']);
    $empty = \App\Models\Skill::factory()->create(['name' => 'Empty Skill', 'instructions' => '']);
    $agent->skills()->attach([$withContent->id, $empty->id]);

    $builder = app(SystemPromptBuilder::class);
    $prompt = $builder->build(agentModel: $agent);
    expect($prompt)
        ->toContain('### Has Content')
        ->not->toContain('### Empty Skill');
});

it('returns no skills section when agent has no skills', function () {
    $agent = \App\Models\Agent::factory()->create();
    // Agent has no skills attached

    $builder = app(SystemPromptBuilder::class);
    $prompt = $builder->build(agentModel: $agent);
    expect($prompt)->not->toContain('Specialized Knowledge');
});
