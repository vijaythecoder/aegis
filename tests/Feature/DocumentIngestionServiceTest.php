<?php

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Rag\DocumentIngestionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Embeddings;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(DocumentIngestionService::class);
    $this->tempDir = storage_path('framework/testing/ingestion');
    if (! is_dir($this->tempDir)) {
        mkdir($this->tempDir, 0755, true);
    }
    Embeddings::fake();
});

afterEach(function () {
    $files = glob($this->tempDir.'/*');
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
});

test('ingests a markdown file into document and chunks', function () {
    $content = <<<'MD'
# Getting Started

This is the getting started guide for the project.

## Installation

Run composer install to get dependencies.

## Configuration

Edit the .env file to configure the app.
MD;

    $path = $this->tempDir.'/guide.md';
    file_put_contents($path, $content);

    $document = $this->service->ingest($path);

    expect($document)->toBeInstanceOf(Document::class);
    expect($document->name)->toBe('guide.md');
    expect($document->path)->toBe($path);
    expect($document->file_type)->toBe('md');
    expect($document->status)->toBe('completed');
    expect($document->chunk_count)->toBeGreaterThan(0);

    $chunks = DocumentChunk::where('document_id', $document->id)->get();
    expect($chunks)->not->toBeEmpty();

    foreach ($chunks as $chunk) {
        expect($chunk->content)->toBeString()->not->toBeEmpty();
        expect($chunk->chunk_index)->toBeInt();
    }
});

test('ingests a PHP code file', function () {
    $code = <<<'PHP'
<?php

namespace App\Services;

class Calculator
{
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }

    public function subtract(int $a, int $b): int
    {
        return $a - $b;
    }
}
PHP;

    $path = $this->tempDir.'/Calculator.php';
    file_put_contents($path, $code);

    $document = $this->service->ingest($path);

    expect($document->status)->toBe('completed');
    expect($document->file_type)->toBe('php');
    expect($document->chunk_count)->toBeGreaterThan(0);
});

test('rejects file exceeding max size', function () {
    config(['aegis.rag.max_file_size_mb' => 0.001]);

    $path = $this->tempDir.'/huge.txt';
    file_put_contents($path, str_repeat('x', 2000));

    $document = $this->service->ingest($path);

    expect($document)->toBeNull();
});

test('returns null for non-existent file', function () {
    $document = $this->service->ingest('/nonexistent/file.txt');

    expect($document)->toBeNull();
});

test('stores embeddings for each chunk in vector store', function () {
    $content = <<<'MD'
# Section One

Content for section one with enough text to be meaningful.

## Section Two

Content for section two with different information.
MD;

    $path = $this->tempDir.'/sections.md';
    file_put_contents($path, $content);

    $document = $this->service->ingest($path);

    $chunks = DocumentChunk::where('document_id', $document->id)->get();

    foreach ($chunks as $chunk) {
        expect($chunk->embedding_id)->not->toBeNull();
    }

    Embeddings::assertGenerated(fn ($prompt) => ! empty($prompt->inputs));
});

test('detects unchanged file and skips re-ingestion', function () {
    $path = $this->tempDir.'/stable.md';
    file_put_contents($path, '# Stable Content');

    $first = $this->service->ingest($path);
    $second = $this->service->ingest($path);

    expect($second->id)->toBe($first->id);
    expect(Document::count())->toBe(1);
});

test('re-ingests file when content changes', function () {
    $path = $this->tempDir.'/changing.md';
    file_put_contents($path, '# Version 1');

    $first = $this->service->ingest($path);
    $firstChunkCount = $first->chunk_count;

    file_put_contents($path, "# Version 2\n\nNew content added here.\n\n## Extra Section\n\nMore content.");

    $second = $this->service->ingest($path);

    expect($second->id)->toBe($first->id);
    expect($second->content_hash)->not->toBe($first->content_hash);
});

test('handles embedding failure gracefully', function () {
    config(['aegis.memory.embedding_provider' => 'disabled']);

    $service = app(DocumentIngestionService::class);

    $path = $this->tempDir.'/no_embed.md';
    file_put_contents($path, "# No Embeddings\n\nContent without embeddings.");

    $document = $service->ingest($path);

    expect($document->status)->toBe('completed');

    $chunks = DocumentChunk::where('document_id', $document->id)->get();
    foreach ($chunks as $chunk) {
        expect($chunk->embedding_id)->toBeNull();
    }
});
