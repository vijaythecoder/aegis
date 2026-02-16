<?php

use App\Agent\AegisAgent;
use App\Agent\AgentLoop;
use App\Agent\PlanningAgent;
use App\Agent\ReflectionAgent;
use App\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('aegis.agent.planning_enabled', true);
    config()->set('aegis.agent.reflection_enabled', true);
    config()->set('aegis.agent.summary_provider', 'anthropic');
    config()->set('aegis.agent.summary_model', 'claude-3-5-haiku-latest');

    $this->conversation = Conversation::factory()->create();
});

it('uses direct execution for simple prompts', function () {
    AegisAgent::fake(['Simple answer']);

    $loop = app(AgentLoop::class);
    $result = $loop->execute('What is PHP?', $this->conversation->id, withStorage: false);

    expect($result->usedPlanning)->toBeFalse()
        ->and($result->response)->toBe('Simple answer')
        ->and($result->plan)->toBeNull()
        ->and($result->retries)->toBe(0);
});

it('uses planned execution for complex prompts', function () {
    PlanningAgent::fake(['STEP 1: Search web using web_search\nSTEP 2: Summarize findings']);
    AegisAgent::fake(['Here is the comprehensive analysis of PHP 8.4 features...']);
    ReflectionAgent::fake(['APPROVED: Response is complete and accurate.']);

    $loop = app(AgentLoop::class);
    $result = $loop->execute(
        'Research the latest PHP 8.4 features and create a detailed summary document with code examples',
        $this->conversation->id,
        withStorage: false,
    );

    expect($result->usedPlanning)->toBeTrue()
        ->and($result->response)->toContain('PHP 8.4')
        ->and($result->retries)->toBe(0);

    PlanningAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'PHP 8.4'));
    AegisAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'execution plan'));
});

it('retries when reflection finds issues', function () {
    PlanningAgent::fake(['STEP 1: Analyze\nSTEP 2: Write']);
    AegisAgent::fake([
        'Incomplete first response',
        'Complete revised response with all details',
    ]);
    ReflectionAgent::fake([
        'NEEDS_REVISION: Missing code examples',
        'APPROVED: Now complete',
    ]);

    $loop = app(AgentLoop::class);
    $result = $loop->execute(
        'Create a comprehensive guide to Laravel middleware with examples and best practices for production use',
        $this->conversation->id,
        withStorage: false,
    );

    expect($result->usedPlanning)->toBeTrue()
        ->and($result->response)->toBe('Complete revised response with all details')
        ->and($result->retries)->toBe(1);
});

it('falls back to direct execution when planning fails', function () {
    PlanningAgent::fake(fn () => throw new RuntimeException('API error'));
    AegisAgent::fake(['Fallback response']);

    $loop = app(AgentLoop::class);
    $result = $loop->execute(
        'Research and summarize the top ten most popular PHP frameworks and their key differences',
        $this->conversation->id,
        withStorage: false,
    );

    expect($result->response)->toBe('Fallback response');
});

it('skips reflection when disabled', function () {
    config()->set('aegis.agent.reflection_enabled', false);

    PlanningAgent::fake(['STEP 1: Do the thing']);
    AegisAgent::fake(['Response without reflection']);

    $loop = app(AgentLoop::class);
    $result = $loop->execute(
        'Build a complete REST API for products with CRUD operations and proper validation',
        $this->conversation->id,
        withStorage: false,
    );

    expect($result->usedPlanning)->toBeTrue()
        ->and($result->response)->toBe('Response without reflection')
        ->and($result->retries)->toBe(0);

    ReflectionAgent::assertNeverPrompted();
});

it('emits step events during execution', function () {
    PlanningAgent::fake(['STEP 1: Plan']);
    AegisAgent::fake(['Response']);
    ReflectionAgent::fake(['APPROVED: Good']);

    $steps = [];

    $loop = app(AgentLoop::class);
    $loop->onStep(function (string $phase, string $detail) use (&$steps) {
        $steps[] = $phase;
    });

    $loop->execute(
        'Create a detailed analysis of the current codebase architecture and suggest improvements for scalability',
        $this->conversation->id,
        withStorage: false,
    );

    expect($steps)->toContain('planning')
        ->and($steps)->toContain('executing');
});

it('caps retries at maximum of two', function () {
    PlanningAgent::fake(['STEP 1: Plan']);
    AegisAgent::fake([
        'First attempt',
        'Second attempt',
        'Third attempt',
    ]);
    ReflectionAgent::fake([
        'NEEDS_REVISION: Missing info',
        'NEEDS_REVISION: Still missing info',
        'NEEDS_REVISION: Never satisfied',
    ]);

    $loop = app(AgentLoop::class);
    $result = $loop->execute(
        'Write a comprehensive tutorial on building real-time applications with Laravel Reverb and WebSockets',
        $this->conversation->id,
        withStorage: false,
    );

    expect($result->retries)->toBeLessThanOrEqual(2);
});

it('detects complex prompts correctly', function () {
    $loop = app(AgentLoop::class);

    expect($loop->requiresPlanning('Hello'))->toBeFalse()
        ->and($loop->requiresPlanning('What is PHP?'))->toBeFalse()
        ->and($loop->requiresPlanning('Create a new migration for the users table and add email verification columns'))->toBeTrue()
        ->and($loop->requiresPlanning('Research the latest PHP features and create a summary document with examples'))->toBeTrue();
});
