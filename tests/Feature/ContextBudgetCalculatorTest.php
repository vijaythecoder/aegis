<?php

use App\Models\Agent;
use App\Models\Skill;
use App\Services\ContextBudgetCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns token budget breakdown for agent', function () {
    $agent = Agent::factory()->create();

    $budget = app(ContextBudgetCalculator::class)->calculate($agent);

    expect($budget)->toHaveKeys([
        'base_prompt', 'skills', 'project_context', 'total', 'model_limit', 'remaining_for_conversation', 'warning',
    ])
        ->and($budget['base_prompt'])->toBeGreaterThan(0)
        ->and($budget['total'])->toBeGreaterThan(0)
        ->and($budget['model_limit'])->toBeGreaterThan(0)
        ->and($budget['remaining_for_conversation'])->toBeGreaterThanOrEqual(0);
});

it('includes skill tokens in budget', function () {
    $agent = Agent::factory()->create();
    $skill = Skill::factory()->create([
        'instructions' => str_repeat('word ', 500),
        'is_active' => true,
    ]);
    $agent->skills()->attach($skill);

    $budgetWith = app(ContextBudgetCalculator::class)->calculate($agent);

    $agentNoSkills = Agent::factory()->create();
    $budgetWithout = app(ContextBudgetCalculator::class)->calculate($agentNoSkills);

    expect($budgetWith['skills'])->toBeGreaterThan($budgetWithout['skills'])
        ->and($budgetWith['total'])->toBeGreaterThan($budgetWithout['total']);
});

it('returns no warning for small prompts', function () {
    $agent = Agent::factory()->create();

    $budget = app(ContextBudgetCalculator::class)->calculate($agent);

    expect($budget['warning'])->toBeNull();
});

it('returns warning when prompt exceeds 30 percent of model context', function () {
    $agent = Agent::factory()->create();

    $largeSkills = [];
    for ($i = 0; $i < 5; $i++) {
        $largeSkills[] = Skill::factory()->create([
            'instructions' => str_repeat('A', 60000),
            'is_active' => true,
        ]);
    }
    $agent->skills()->attach(collect($largeSkills)->pluck('id'));

    $budget = app(ContextBudgetCalculator::class)->calculate($agent);

    expect($budget['warning'])->not->toBeNull()
        ->and($budget['warning'])->toContain('System prompt uses');
});

it('calculates remaining conversation tokens', function () {
    $agent = Agent::factory()->create();

    $budget = app(ContextBudgetCalculator::class)->calculate($agent);

    expect($budget['remaining_for_conversation'])->toBe($budget['model_limit'] - $budget['total']);
});

it('uses agent provider and model when specified', function () {
    $agent = Agent::factory()->create([
        'provider' => 'anthropic',
        'model' => 'claude-sonnet-4-20250514',
    ]);

    $budget = app(ContextBudgetCalculator::class)->calculate($agent);

    expect($budget['model_limit'])->toBeGreaterThan(0);
});
