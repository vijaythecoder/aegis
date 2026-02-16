<?php

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Tools\KnowledgeSearchTool;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns matching document chunks for a search query', function () {
    $doc = Document::create([
        'name' => 'sia-guide.md',
        'path' => '/tmp/sia-guide.md',
        'file_type' => 'md',
        'file_size' => 1024,
        'status' => 'completed',
        'chunk_count' => 2,
    ]);

    DocumentChunk::create([
        'document_id' => $doc->id,
        'content' => 'SIA Template System provides a modular approach to building templates.',
        'chunk_index' => 0,
    ]);

    DocumentChunk::create([
        'document_id' => $doc->id,
        'content' => 'The configuration uses YAML files for defining layouts.',
        'chunk_index' => 1,
    ]);

    $tool = app(KnowledgeSearchTool::class);
    $request = new \Laravel\Ai\Tools\Request(['query' => 'SIA template']);
    $result = $tool->handle($request);

    expect($result)->toContain('SIA Template System')
        ->and($result)->toContain('sia-guide.md');
});

it('returns no results message when query does not match', function () {
    $tool = app(KnowledgeSearchTool::class);
    $request = new \Laravel\Ai\Tools\Request(['query' => 'nonexistent-topic-xyz']);
    $result = $tool->handle($request);

    expect($result)->toBe('No matching documents found in the knowledge base.');
});

it('returns no results message for empty query', function () {
    $tool = app(KnowledgeSearchTool::class);
    $request = new \Laravel\Ai\Tools\Request(['query' => '']);
    $result = $tool->handle($request);

    expect($result)->toBe('No results found. Please provide a search query.');
});

it('has correct tool metadata', function () {
    $tool = app(KnowledgeSearchTool::class);

    expect($tool->name())->toBe('knowledge_search')
        ->and((string) $tool->description())->toContain('knowledge base');
});

it('is auto-discovered by ToolRegistry', function () {
    $registry = app(\App\Tools\ToolRegistry::class);

    expect($registry->get('knowledge_search'))->toBeInstanceOf(KnowledgeSearchTool::class);
});
