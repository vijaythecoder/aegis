<?php

use App\Memory\EmbeddingService;
use Laravel\Ai\Embeddings;

beforeEach(function () {
    $this->service = app(EmbeddingService::class);
});

it('generates an embedding vector via SDK', function () {
    Embeddings::fake();

    $result = $this->service->embed('Hello world');

    expect($result)
        ->toBeArray()
        ->not->toBeEmpty();

    Embeddings::assertGenerated(fn ($prompt) => in_array('Hello world', $prompt->inputs));
});

it('generates embedding with configured dimensions', function () {
    config(['aegis.memory.embedding_dimensions' => 384]);

    Embeddings::fake();

    $result = $this->service->embed('Test text');

    expect($result)->toHaveCount(384);
});

it('uses default 768 dimensions when not configured', function () {
    config(['aegis.memory.embedding_dimensions' => 768]);

    Embeddings::fake();

    $result = $this->service->embed('Test text');

    expect($result)->toHaveCount(768);
});

it('returns null when provider is disabled', function () {
    config(['aegis.memory.embedding_provider' => 'disabled']);

    $result = $this->service->embed('Test text');

    expect($result)->toBeNull();

    Embeddings::assertNothingGenerated();
});

it('returns null when provider throws exception', function () {
    Embeddings::fake(function () {
        throw new RuntimeException('Provider unavailable');
    });

    $result = $this->service->embed('Test text');

    expect($result)->toBeNull();
});

it('generates batch embeddings for multiple texts', function () {
    Embeddings::fake();

    $results = $this->service->embedBatch(['Hello', 'World', 'Test']);

    expect($results)
        ->toBeArray()
        ->toHaveCount(3);

    foreach ($results as $embedding) {
        expect($embedding)->toBeArray()->not->toBeEmpty();
    }
});

it('returns empty array for batch when provider is disabled', function () {
    config(['aegis.memory.embedding_provider' => 'disabled']);

    $results = $this->service->embedBatch(['Hello', 'World']);

    expect($results)->toBeEmpty();
});

it('uses configured provider from aegis config', function () {
    config(['aegis.memory.embedding_provider' => 'ollama']);
    config(['aegis.memory.embedding_model' => 'nomic-embed-text']);

    Embeddings::fake();

    $this->service->embed('Test');

    Embeddings::assertGenerated(fn ($prompt) => $prompt->provider->name() === 'ollama'
        && $prompt->model === 'nomic-embed-text'
    );
});

it('uses openai provider when configured', function () {
    config(['aegis.memory.embedding_provider' => 'openai']);
    config(['aegis.memory.embedding_model' => 'text-embedding-3-small']);

    Embeddings::fake();

    $this->service->embed('Test');

    Embeddings::assertGenerated(fn ($prompt) => $prompt->provider->name() === 'openai'
        && $prompt->model === 'text-embedding-3-small'
    );
});

it('returns embedding as array of floats', function () {
    $fakeVector = Embeddings::fakeEmbedding(768);

    Embeddings::fake([[$fakeVector]]);

    $result = $this->service->embed('Test');

    expect($result)->toBe($fakeVector);

    foreach ($result as $value) {
        expect($value)->toBeFloat();
    }
});

it('returns cached embedding on second call with same text', function () {
    Embeddings::fake();

    $first = $this->service->embed('Cache me');
    $second = $this->service->embed('Cache me');

    expect($first)->toBe($second);

    $cacheKey = 'embedding:'.hash('xxh128', 'Cache me');
    expect(\Illuminate\Support\Facades\Cache::has($cacheKey))->toBeTrue();
});

it('skips API call for cached content', function () {
    $fakeVector = Embeddings::fakeEmbedding(768);
    $cacheKey = 'embedding:'.hash('xxh128', 'Precached text');

    \Illuminate\Support\Facades\Cache::put($cacheKey, $fakeVector, now()->addDay());

    Embeddings::fake();

    $result = $this->service->embed('Precached text');

    expect($result)->toBe($fakeVector);

    Embeddings::assertNothingGenerated();
});
