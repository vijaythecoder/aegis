<?php

use App\Enums\MemoryType;
use App\Memory\EmbeddingService;
use App\Memory\HybridSearchService;
use App\Memory\MemoryService;
use App\Memory\VectorStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Reranking;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->vectorStore = app(VectorStore::class);
    $this->memoryService = app(MemoryService::class);
    $this->embeddingService = app(EmbeddingService::class);
    $this->service = app(HybridSearchService::class);
});

it('returns vector results when only vector data exists', function () {
    $embedding = Embeddings::fakeEmbedding(768);

    $this->vectorStore->store($embedding, [
        'source_type' => 'message',
        'source_id' => 1,
        'content_preview' => 'Vector only result',
    ]);

    Embeddings::fake();

    $results = $this->service->search('test query', $embedding);

    expect($results)->toHaveCount(1);
    expect($results->first()['content_preview'])->toBe('Vector only result');
    expect($results->first())->toHaveKey('score');
});

it('returns fts results when only keyword data exists', function () {
    $this->memoryService->store(MemoryType::Fact, 'user_name', 'John Doe');

    $results = $this->service->search('John Doe');

    expect($results)->not->toBeEmpty();
    expect($results->first())->toHaveKey('content_preview');
    expect($results->first())->toHaveKey('score');
});

it('combines vector and fts results with score fusion', function () {
    $embedding = Embeddings::fakeEmbedding(768);

    $this->vectorStore->store($embedding, [
        'source_type' => 'message',
        'source_id' => 1,
        'content_preview' => 'Vector result about cats',
    ]);

    $this->memoryService->store(MemoryType::Fact, 'pet_preference', 'I love cats');

    Embeddings::fake();

    $results = $this->service->search('cats', $embedding);

    expect($results->count())->toBeGreaterThanOrEqual(2);
});

it('deduplicates results by source', function () {
    $embedding = Embeddings::fakeEmbedding(768);

    $memory = $this->memoryService->store(MemoryType::Fact, 'test_key', 'Duplicate content');

    $this->vectorStore->store($embedding, [
        'source_type' => 'memory',
        'source_id' => $memory->id,
        'content_preview' => 'Duplicate content',
    ]);

    Embeddings::fake();

    $results = $this->service->search('Duplicate content', $embedding);

    $uniqueKeys = $results->map(fn ($r) => $r['source_type'].'_'.$r['source_id'])->unique();
    expect($uniqueKeys->count())->toBe($results->count());
});

it('respects limit parameter', function () {
    for ($i = 1; $i <= 10; $i++) {
        $this->vectorStore->store(Embeddings::fakeEmbedding(768), [
            'source_type' => 'message',
            'source_id' => $i,
            'content_preview' => "Result {$i}",
        ]);
    }

    Embeddings::fake();

    $results = $this->service->search('test', Embeddings::fakeEmbedding(768), limit: 3);

    expect($results)->toHaveCount(3);
});

it('returns empty collection when no data exists', function () {
    $results = $this->service->search('nonexistent query');

    expect($results)->toBeEmpty();
});

it('uses configurable alpha weight for score fusion', function () {
    config(['aegis.memory.hybrid_search_alpha' => 1.0]);

    $embedding = Embeddings::fakeEmbedding(768);

    $this->vectorStore->store($embedding, [
        'source_type' => 'message',
        'source_id' => 1,
        'content_preview' => 'Pure vector result',
    ]);

    Embeddings::fake();

    $results = $this->service->search('test', $embedding);

    expect($results)->not->toBeEmpty();
});

it('reranks results when reranking is enabled', function () {
    config(['aegis.memory.reranking_enabled' => true]);
    Reranking::fake();

    $embedding = Embeddings::fakeEmbedding(768);

    for ($i = 1; $i <= 3; $i++) {
        $this->vectorStore->store($embedding, [
            'source_type' => 'message',
            'source_id' => $i,
            'content_preview' => "Result about topic {$i}",
        ]);
    }

    Embeddings::fake();

    $results = $this->service->search('topic', $embedding);

    expect($results)->toHaveCount(3);
    expect($results->first())->toHaveKey('score');

    Reranking::assertReranked(fn ($prompt) => $prompt->query === 'topic'
        && count($prompt->documents) === 3);
});

it('skips reranking when disabled', function () {
    config(['aegis.memory.reranking_enabled' => false]);
    Reranking::fake();

    $embedding = Embeddings::fakeEmbedding(768);

    $this->vectorStore->store($embedding, [
        'source_type' => 'message',
        'source_id' => 1,
        'content_preview' => 'Some result',
    ]);

    Embeddings::fake();

    $results = $this->service->search('test', $embedding);

    expect($results)->not->toBeEmpty();

    Reranking::assertNothingReranked();
});

it('falls back to original results when reranking fails', function () {
    config(['aegis.memory.reranking_enabled' => true]);
    Reranking::fake(fn () => throw new RuntimeException('Reranking API down'));

    $embedding = Embeddings::fakeEmbedding(768);

    for ($i = 1; $i <= 2; $i++) {
        $this->vectorStore->store($embedding, [
            'source_type' => 'message',
            'source_id' => $i,
            'content_preview' => "Fallback result {$i}",
        ]);
    }

    Embeddings::fake();

    $results = $this->service->search('test', $embedding);

    expect($results)->toHaveCount(2);
});

it('skips reranking for single result', function () {
    config(['aegis.memory.reranking_enabled' => true]);
    Reranking::fake();

    $embedding = Embeddings::fakeEmbedding(768);

    $this->vectorStore->store($embedding, [
        'source_type' => 'message',
        'source_id' => 1,
        'content_preview' => 'Single result',
    ]);

    Embeddings::fake();

    $results = $this->service->search('test', $embedding);

    expect($results)->toHaveCount(1);

    Reranking::assertNothingReranked();
});
