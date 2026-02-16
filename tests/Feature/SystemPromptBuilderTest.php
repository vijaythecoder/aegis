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
        ->and($prompt)->toContain('Memory Recall')
        ->and($prompt)->toContain('memory_recall')
        ->and($prompt)->toContain('memory_store');
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

    expect($prompt)->toContain('Known facts about the user')
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

it('includes memory recall instructions', function () {
    $builder = app(SystemPromptBuilder::class);
    $prompt = $builder->build();

    expect($prompt)->toContain('MANDATORY')
        ->and($prompt)->toContain('memory_recall')
        ->and($prompt)->toContain('memory_store');
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
