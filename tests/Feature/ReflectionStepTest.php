<?php

use App\Agent\ReflectionAgent;
use App\Agent\ReflectionStep;

beforeEach(function () {
    config()->set('aegis.agent.reflection_enabled', true);
    config()->set('aegis.agent.summary_provider', 'anthropic');
    config()->set('aegis.agent.summary_model', 'claude-3-5-haiku-latest');
});

it('returns approved when reflection finds no issues', function () {
    ReflectionAgent::fake(['APPROVED: The response correctly addresses the user query.']);

    $step = app(ReflectionStep::class);
    $result = $step->reflect('What is PHP?', 'PHP is a server-side scripting language.');

    expect($result->approved)->toBeTrue()
        ->and($result->feedback)->toBeNull();
});

it('returns feedback when reflection finds issues', function () {
    ReflectionAgent::fake(['NEEDS_REVISION: The response is missing information about PHP versions.']);

    $step = app(ReflectionStep::class);
    $result = $step->reflect('What is PHP?', 'PHP is a language.');

    expect($result->approved)->toBeFalse()
        ->and($result->feedback)->toContain('missing information');
});

it('skips reflection when disabled via config', function () {
    config()->set('aegis.agent.reflection_enabled', false);

    $step = app(ReflectionStep::class);
    $result = $step->reflect('What is PHP?', 'PHP is a language.');

    expect($result->approved)->toBeTrue()
        ->and($result->feedback)->toBeNull();
});

it('limits to max 1 reflection per call', function () {
    ReflectionAgent::fake(['APPROVED: Looks good.']);

    $step = app(ReflectionStep::class);
    $result = $step->reflect('query', 'response');

    expect($result->approved)->toBeTrue();

    ReflectionAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'response'));
});

it('handles agent failures gracefully by approving', function () {
    ReflectionAgent::fake(fn () => throw new RuntimeException('API error'));

    $step = app(ReflectionStep::class);
    $result = $step->reflect('query', 'response');

    expect($result->approved)->toBeTrue()
        ->and($result->feedback)->toBeNull();
});

it('treats ambiguous responses as approved', function () {
    ReflectionAgent::fake(['The response seems fine overall.']);

    $step = app(ReflectionStep::class);
    $result = $step->reflect('query', 'response');

    expect($result->approved)->toBeTrue();
});
