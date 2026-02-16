<?php

use App\Enums\MemoryType;
use App\Memory\MemoryService;
use App\Memory\VectorStore;
use App\Tools\MemoryRecallTool;
use App\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tool = app(MemoryRecallTool::class);
});

it('implements the SDK Tool contract', function () {
    expect($this->tool)->toBeInstanceOf(\Laravel\Ai\Contracts\Tool::class);
});

it('has correct name and description', function () {
    expect($this->tool->name())->toBe('memory_recall');
    expect((string) $this->tool->description())->toContain('Search');
});

it('returns results from FTS memory search', function () {
    $memoryService = app(MemoryService::class);
    $memoryService->store(MemoryType::Fact, 'user_name', 'John Doe');
    $memoryService->store(MemoryType::Preference, 'editor', 'VS Code');

    $request = new Request(['query' => 'John']);

    $result = (string) $this->tool->handle($request);

    expect($result)->toContain('John Doe');
});

it('returns results from vector search when embedding provided', function () {
    $vectorStore = app(VectorStore::class);
    $embedding = Embeddings::fakeEmbedding(768);

    $vectorStore->store($embedding, [
        'source_type' => 'message',
        'source_id' => 1,
        'content_preview' => 'We discussed Laravel migrations yesterday',
    ]);

    Embeddings::fake();

    $request = new Request(['query' => 'Laravel migrations']);

    $result = (string) $this->tool->handle($request);

    expect($result)->toContain('Laravel migrations');
});

it('returns no results message for empty query', function () {
    $request = new Request(['query' => '']);

    $result = (string) $this->tool->handle($request);

    expect($result)->toContain('No memories found');
});

it('returns no results message when nothing matches', function () {
    $request = new Request(['query' => 'xyznonexistent123']);

    $result = (string) $this->tool->handle($request);

    expect($result)->toContain('No memories found');
});

it('is auto-discovered by ToolRegistry', function () {
    $registry = app(ToolRegistry::class);

    expect($registry->get('memory_recall'))->toBeInstanceOf(MemoryRecallTool::class);
});
