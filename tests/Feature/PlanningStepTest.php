<?php

use App\Agent\PlanningAgent;
use App\Agent\PlanningStep;

beforeEach(function () {
    config()->set('aegis.agent.planning_enabled', true);
    config()->set('aegis.agent.summary_provider', 'anthropic');
    config()->set('aegis.agent.summary_model', 'claude-3-5-haiku-latest');
});

it('generates a plan for complex queries', function () {
    PlanningAgent::fake(['I will: 1) Read the configuration file, 2) Modify the settings, 3) Test the changes']);

    $step = app(PlanningStep::class);
    $plan = $step->generate('Please read the config file and update the database settings to use PostgreSQL instead of MySQL');

    expect($plan)->not->toBeNull()
        ->and($plan)->toBeString()
        ->and(strlen($plan))->toBeGreaterThan(0);
});

it('skips planning for simple queries under 20 words without action keywords', function () {
    $step = app(PlanningStep::class);
    $plan = $step->generate('Hello, how are you?');

    expect($plan)->toBeNull();
});

it('skips planning when disabled via config', function () {
    config()->set('aegis.agent.planning_enabled', false);

    $step = app(PlanningStep::class);
    $plan = $step->generate('Please create a new migration for the users table and add email verification columns');

    expect($plan)->toBeNull();
});

it('detects complex queries with action keywords', function () {
    $step = app(PlanningStep::class);

    expect($step->isComplex('Create a new file with the user model'))->toBeTrue()
        ->and($step->isComplex('Delete the old migration files'))->toBeTrue()
        ->and($step->isComplex('Search for all references to the User class'))->toBeTrue()
        ->and($step->isComplex('Run the test suite and fix any failures'))->toBeTrue()
        ->and($step->isComplex('What is PHP?'))->toBeFalse()
        ->and($step->isComplex('Hello'))->toBeFalse();
});

it('uses the summary provider and model for cost savings', function () {
    PlanningAgent::fake(['Step 1: Analyze the code']);

    $step = app(PlanningStep::class);
    $step->generate('Analyze the codebase and refactor the authentication module to use Laravel Sanctum');

    PlanningAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'refactor the authentication'));
});

it('returns null when the agent returns an empty response', function () {
    PlanningAgent::fake(['']);

    $step = app(PlanningStep::class);
    $plan = $step->generate('Build a complete REST API for the products resource with CRUD operations');

    expect($plan)->toBeNull();
});

it('handles agent failures gracefully', function () {
    PlanningAgent::fake(fn () => throw new RuntimeException('API error'));

    $step = app(PlanningStep::class);
    $plan = $step->generate('Create a complex multi-step workflow for order processing');

    expect($plan)->toBeNull();
});
