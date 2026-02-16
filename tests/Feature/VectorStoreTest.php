<?php

use App\Memory\VectorStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Embeddings;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->store = app(VectorStore::class);
});

it('stores an embedding with metadata', function () {
    $embedding = Embeddings::fakeEmbedding(768);

    $id = $this->store->store($embedding, [
        'source_type' => 'message',
        'source_id' => 42,
        'content_preview' => 'Hello world',
    ]);

    expect($id)->toBeInt()->toBeGreaterThan(0);
});

it('retrieves stored embeddings by similarity search', function () {
    $target = Embeddings::fakeEmbedding(768);

    $this->store->store($target, [
        'source_type' => 'message',
        'source_id' => 1,
        'content_preview' => 'Target result',
    ]);

    $this->store->store(Embeddings::fakeEmbedding(768), [
        'source_type' => 'message',
        'source_id' => 2,
        'content_preview' => 'Other result',
    ]);

    $results = $this->store->search($target, 5);

    expect($results)->toHaveCount(2);
    expect($results->first()['content_preview'])->toBe('Target result');
    expect($results->first()['score'])->toBeGreaterThan(0.99);
});

it('respects limit parameter in search', function () {
    for ($i = 1; $i <= 10; $i++) {
        $this->store->store(Embeddings::fakeEmbedding(768), [
            'source_type' => 'message',
            'source_id' => $i,
            'content_preview' => "Result {$i}",
        ]);
    }

    $results = $this->store->search(Embeddings::fakeEmbedding(768), 3);

    expect($results)->toHaveCount(3);
});

it('deletes an embedding by id', function () {
    $embedding = Embeddings::fakeEmbedding(768);

    $id = $this->store->store($embedding, [
        'source_type' => 'message',
        'source_id' => 1,
        'content_preview' => 'To be deleted',
    ]);

    $this->store->delete($id);

    $results = $this->store->search($embedding, 5);

    expect($results)->toBeEmpty();
});

it('deletes embeddings by source', function () {
    $this->store->store(Embeddings::fakeEmbedding(768), [
        'source_type' => 'message',
        'source_id' => 1,
        'content_preview' => 'Message 1',
    ]);

    $this->store->store(Embeddings::fakeEmbedding(768), [
        'source_type' => 'message',
        'source_id' => 2,
        'content_preview' => 'Message 2',
    ]);

    $this->store->store(Embeddings::fakeEmbedding(768), [
        'source_type' => 'document',
        'source_id' => 1,
        'content_preview' => 'Document 1',
    ]);

    $this->store->deleteBySource('message', 1);

    $results = $this->store->search(Embeddings::fakeEmbedding(768), 10);

    expect($results)->toHaveCount(2);
    expect($results->pluck('source_type')->unique()->values()->all())->not->toContain('message_1');
});

it('returns results sorted by similarity score descending', function () {
    $base = array_fill(0, 768, 0.0);

    $exact = $base;
    $exact[0] = 1.0;

    $similar = $base;
    $similar[0] = 0.9;
    $similar[1] = 0.1;

    $different = $base;
    $different[0] = 0.1;
    $different[1] = 0.9;

    $this->store->store($exact, [
        'source_type' => 'message',
        'source_id' => 1,
        'content_preview' => 'Exact match',
    ]);

    $this->store->store($similar, [
        'source_type' => 'message',
        'source_id' => 2,
        'content_preview' => 'Similar match',
    ]);

    $this->store->store($different, [
        'source_type' => 'message',
        'source_id' => 3,
        'content_preview' => 'Different match',
    ]);

    $query = $base;
    $query[0] = 1.0;

    $results = $this->store->search($query, 3);

    expect($results[0]['content_preview'])->toBe('Exact match');
    expect($results[0]['score'])->toBeGreaterThan($results[1]['score']);
    expect($results[1]['score'])->toBeGreaterThan($results[2]['score']);
});

it('returns empty collection when no embeddings stored', function () {
    $results = $this->store->search(Embeddings::fakeEmbedding(768), 5);

    expect($results)->toBeEmpty();
});

it('stores and retrieves metadata correctly', function () {
    $embedding = Embeddings::fakeEmbedding(768);

    $this->store->store($embedding, [
        'source_type' => 'document_chunk',
        'source_id' => 99,
        'content_preview' => 'A chunk of text from a document',
        'conversation_id' => 5,
    ]);

    $results = $this->store->search($embedding, 1);

    expect($results->first())
        ->toHaveKey('source_type', 'document_chunk')
        ->toHaveKey('source_id', 99)
        ->toHaveKey('content_preview', 'A chunk of text from a document');
});
