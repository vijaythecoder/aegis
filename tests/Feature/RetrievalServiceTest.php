<?php

use App\Memory\EmbeddingService;
use App\Memory\VectorStore;
use App\Models\Document;
use App\Models\DocumentChunk;
use App\Rag\RetrievalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Embeddings;

uses(RefreshDatabase::class);

beforeEach(function () {
    Embeddings::fake();
    $this->service = app(RetrievalService::class);
});

test('retrieves relevant document chunks by query', function () {
    $document = Document::create([
        'name' => 'guide.md',
        'path' => '/tmp/guide.md',
        'file_type' => 'md',
        'file_size' => 500,
        'chunk_count' => 2,
        'status' => 'completed',
    ]);

    $embedding = app(EmbeddingService::class)->embed('authentication setup');
    $vectorStore = app(VectorStore::class);

    $embId1 = $vectorStore->store($embedding ?? array_fill(0, 768, 0.1), [
        'source_type' => 'document_chunk',
        'source_id' => $document->id,
        'content_preview' => 'How to set up authentication',
    ]);

    DocumentChunk::create([
        'document_id' => $document->id,
        'content' => 'To set up authentication, configure the auth guard in config/auth.php.',
        'metadata' => ['file_type' => 'markdown'],
        'embedding_id' => $embId1,
        'chunk_index' => 0,
        'start_line' => 1,
        'end_line' => 5,
    ]);

    $embId2 = $vectorStore->store($embedding ?? array_fill(0, 768, 0.1), [
        'source_type' => 'document_chunk',
        'source_id' => $document->id,
        'content_preview' => 'Database configuration details',
    ]);

    DocumentChunk::create([
        'document_id' => $document->id,
        'content' => 'Configure the database by editing the .env file with your credentials.',
        'metadata' => ['file_type' => 'markdown'],
        'embedding_id' => $embId2,
        'chunk_index' => 1,
        'start_line' => 6,
        'end_line' => 10,
    ]);

    $results = $this->service->retrieve('authentication setup');

    expect($results)->toBeArray()->not->toBeEmpty();

    foreach ($results as $result) {
        expect($result)->toHaveKeys(['content', 'score', 'document_name', 'file_type', 'chunk_index']);
        expect($result['content'])->toBeString()->not->toBeEmpty();
        expect($result['score'])->toBeFloat();
    }
});

test('respects max retrieval results limit', function () {
    $document = Document::create([
        'name' => 'big.md',
        'path' => '/tmp/big.md',
        'file_type' => 'md',
        'file_size' => 1000,
        'chunk_count' => 15,
        'status' => 'completed',
    ]);

    $vectorStore = app(VectorStore::class);

    for ($i = 0; $i < 15; $i++) {
        $embedding = array_fill(0, 768, 0.1 + ($i * 0.01));
        $embId = $vectorStore->store($embedding, [
            'source_type' => 'document_chunk',
            'source_id' => $document->id,
            'content_preview' => "Chunk {$i}",
        ]);

        DocumentChunk::create([
            'document_id' => $document->id,
            'content' => "Content of chunk number {$i} with some text.",
            'metadata' => ['file_type' => 'markdown'],
            'embedding_id' => $embId,
            'chunk_index' => $i,
        ]);
    }

    config(['aegis.rag.max_retrieval_results' => 5]);

    $results = $this->service->retrieve('chunk content');

    expect(count($results))->toBeLessThanOrEqual(5);
});

test('returns empty array when no documents exist', function () {
    $results = $this->service->retrieve('anything');

    expect($results)->toBeArray()->toBeEmpty();
});

test('filters results by document path', function () {
    $doc1 = Document::create([
        'name' => 'auth.md',
        'path' => '/project/docs/auth.md',
        'file_type' => 'md',
        'file_size' => 200,
        'chunk_count' => 1,
        'status' => 'completed',
    ]);

    $doc2 = Document::create([
        'name' => 'db.md',
        'path' => '/project/docs/db.md',
        'file_type' => 'md',
        'file_size' => 200,
        'chunk_count' => 1,
        'status' => 'completed',
    ]);

    $vectorStore = app(VectorStore::class);

    $emb1 = $vectorStore->store(array_fill(0, 768, 0.5), [
        'source_type' => 'document_chunk',
        'source_id' => $doc1->id,
        'content_preview' => 'Auth content',
    ]);

    DocumentChunk::create([
        'document_id' => $doc1->id,
        'content' => 'Authentication guide content.',
        'embedding_id' => $emb1,
        'chunk_index' => 0,
    ]);

    $emb2 = $vectorStore->store(array_fill(0, 768, 0.5), [
        'source_type' => 'document_chunk',
        'source_id' => $doc2->id,
        'content_preview' => 'DB content',
    ]);

    DocumentChunk::create([
        'document_id' => $doc2->id,
        'content' => 'Database guide content.',
        'embedding_id' => $emb2,
        'chunk_index' => 0,
    ]);

    $results = $this->service->retrieve('guide', documentPath: '/project/docs/auth.md');

    foreach ($results as $result) {
        expect($result['document_name'])->toBe('auth.md');
    }
});

test('results are sorted by score descending', function () {
    $document = Document::create([
        'name' => 'sorted.md',
        'path' => '/tmp/sorted.md',
        'file_type' => 'md',
        'file_size' => 500,
        'chunk_count' => 3,
        'status' => 'completed',
    ]);

    $vectorStore = app(VectorStore::class);

    for ($i = 0; $i < 3; $i++) {
        $embedding = array_fill(0, 768, 0.1 * ($i + 1));
        $embId = $vectorStore->store($embedding, [
            'source_type' => 'document_chunk',
            'source_id' => $document->id,
            'content_preview' => "Chunk {$i}",
        ]);

        DocumentChunk::create([
            'document_id' => $document->id,
            'content' => "Sorted chunk {$i}.",
            'embedding_id' => $embId,
            'chunk_index' => $i,
        ]);
    }

    $results = $this->service->retrieve('sorted chunk');

    if (count($results) >= 2) {
        for ($i = 0; $i < count($results) - 1; $i++) {
            expect($results[$i]['score'])->toBeGreaterThanOrEqual($results[$i + 1]['score']);
        }
    }
});
