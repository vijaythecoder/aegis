<?php

use App\Agent\Middleware\InjectMemoryContext;
use App\Memory\EmbeddingService;
use App\Memory\HybridSearchService;
use App\Memory\TemporalParser;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Contracts\Providers\TextProvider;
use Laravel\Ai\Prompts\AgentPrompt;

beforeEach(function () {
    $this->searchService = Mockery::mock(HybridSearchService::class);
    $this->embeddingService = Mockery::mock(EmbeddingService::class);
    $this->temporalParser = new TemporalParser;
    $this->agent = Mockery::mock(Agent::class);
    $this->provider = Mockery::mock(TextProvider::class);

    $this->middleware = new InjectMemoryContext(
        $this->searchService,
        $this->embeddingService,
        $this->temporalParser,
    );
});

function makePrompt($test, string $text): AgentPrompt
{
    return new AgentPrompt($test->agent, $text, [], $test->provider, 'test-model');
}

it('injects memory context into prompt', function () {
    $this->embeddingService->shouldReceive('embed')
        ->once()
        ->andReturn([0.1, 0.2, 0.3]);

    $this->searchService->shouldReceive('search')
        ->once()
        ->andReturn(collect([
            ['source_type' => 'memory', 'source_id' => 1, 'content_preview' => 'User prefers dark mode', 'score' => 0.5],
        ]));

    $prompt = makePrompt($this, 'What theme do I like?');
    $result = null;

    $this->middleware->handle($prompt, function (AgentPrompt $p) use (&$result) {
        $result = $p;

        return $p;
    });

    expect($result->prompt)->toContain('Relevant Memories')
        ->and($result->prompt)->toContain('User prefers dark mode')
        ->and($result->prompt)->not->toContain('HIGHLY RELEVANT');
});

it('flags high-relevance memories with proactive hint', function () {
    $this->embeddingService->shouldReceive('embed')
        ->once()
        ->andReturn([0.1, 0.2, 0.3]);

    $this->searchService->shouldReceive('search')
        ->once()
        ->andReturn(collect([
            ['source_type' => 'memory', 'source_id' => 1, 'content_preview' => 'User name is Vijay', 'score' => 0.95],
            ['source_type' => 'memory', 'source_id' => 2, 'content_preview' => 'User likes PHP', 'score' => 0.5],
        ]));

    $prompt = makePrompt($this, 'What is my name?');
    $result = null;

    $this->middleware->handle($prompt, function (AgentPrompt $p) use (&$result) {
        $result = $p;

        return $p;
    });

    expect($result->prompt)->toContain('HIGHLY RELEVANT')
        ->and($result->prompt)->toContain('User name is Vijay')
        ->and($result->prompt)->toContain('Proactively incorporate');
});

it('does not add proactive instruction when no high-relevance results', function () {
    $this->embeddingService->shouldReceive('embed')
        ->once()
        ->andReturn([0.1, 0.2, 0.3]);

    $this->searchService->shouldReceive('search')
        ->once()
        ->andReturn(collect([
            ['source_type' => 'memory', 'source_id' => 1, 'content_preview' => 'Some memory', 'score' => 0.5],
        ]));

    $prompt = makePrompt($this, 'Tell me something');
    $result = null;

    $this->middleware->handle($prompt, function (AgentPrompt $p) use (&$result) {
        $result = $p;

        return $p;
    });

    expect($result->prompt)->not->toContain('Proactively incorporate');
});

it('skips injection when auto_recall is disabled', function () {
    config(['aegis.memory.auto_recall' => false]);

    $prompt = makePrompt($this, 'Hello');
    $result = null;

    $this->middleware->handle($prompt, function (AgentPrompt $p) use (&$result) {
        $result = $p;

        return $p;
    });

    expect($result->prompt)->toBe('Hello');
});

it('skips injection on empty message', function () {
    $prompt = makePrompt($this, '');
    $result = null;

    $this->middleware->handle($prompt, function (AgentPrompt $p) use (&$result) {
        $result = $p;

        return $p;
    });

    expect($result->prompt)->toBe('');
});

it('filters out low-score results below threshold', function () {
    $this->embeddingService->shouldReceive('embed')
        ->once()
        ->andReturn([0.1, 0.2, 0.3]);

    $this->searchService->shouldReceive('search')
        ->once()
        ->andReturn(collect([
            ['source_type' => 'memory', 'source_id' => 1, 'content_preview' => 'Irrelevant', 'score' => 0.1],
        ]));

    $prompt = makePrompt($this, 'Tell me something random');
    $result = null;

    $this->middleware->handle($prompt, function (AgentPrompt $p) use (&$result) {
        $result = $p;

        return $p;
    });

    expect($result->prompt)->toBe('Tell me something random');
});
