<?php

use App\Livewire\KnowledgeBase;
use App\Models\Document;
use App\Models\DocumentChunk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Embeddings;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    Embeddings::fake();
});

test('knowledge base page is accessible', function () {
    $this->get('/knowledge')->assertStatus(200);
});

test('knowledge base page renders livewire component', function () {
    $this->get('/knowledge')->assertSeeLivewire('knowledge-base');
});

test('shows empty state when no documents', function () {
    Livewire::test(KnowledgeBase::class)
        ->assertSee('No documents ingested yet');
});

test('lists ingested documents', function () {
    Document::create([
        'name' => 'guide.md',
        'path' => '/tmp/guide.md',
        'file_type' => 'md',
        'file_size' => 1024,
        'chunk_count' => 5,
        'status' => 'completed',
    ]);

    Livewire::test(KnowledgeBase::class)
        ->assertSee('guide.md')
        ->assertSee('5 chunks')
        ->assertSee('Completed');
});

test('can delete a document', function () {
    $doc = Document::create([
        'name' => 'delete-me.md',
        'path' => '/tmp/delete-me.md',
        'file_type' => 'md',
        'file_size' => 100,
        'chunk_count' => 1,
        'status' => 'completed',
    ]);

    DocumentChunk::create([
        'document_id' => $doc->id,
        'content' => 'Some content',
        'chunk_index' => 0,
    ]);

    Livewire::test(KnowledgeBase::class)
        ->call('deleteDocument', $doc->id)
        ->assertSee('Document deleted');

    expect(Document::count())->toBe(0);
    expect(DocumentChunk::count())->toBe(0);
});

test('shows upload form', function () {
    Livewire::test(KnowledgeBase::class)
        ->assertSee('Upload Document')
        ->assertSee('Drop a file here');
});

test('sidebar has knowledge base link', function () {
    $this->get('/chat')
        ->assertSee('Knowledge Base');
});
