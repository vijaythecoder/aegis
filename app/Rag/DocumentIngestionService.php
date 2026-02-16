<?php

namespace App\Rag;

use App\Memory\EmbeddingService;
use App\Memory\VectorStore;
use App\Models\Document;
use App\Models\DocumentChunk;
use Illuminate\Support\Facades\Log;
use Throwable;

class DocumentIngestionService
{
    private const BATCH_SIZE = 10;

    public function __construct(
        private ChunkingService $chunker,
        private EmbeddingService $embedder,
        private VectorStore $vectorStore,
    ) {}

    public function ingest(string $path): ?Document
    {
        if (! file_exists($path) || ! is_readable($path)) {
            return null;
        }

        $fileSize = filesize($path);
        $maxBytes = config('aegis.rag.max_file_size_mb', 10) * 1024 * 1024;

        if ($fileSize > $maxBytes) {
            Log::warning('DocumentIngestionService: file exceeds max size', ['path' => $path]);

            return null;
        }

        $contentHash = hash_file('sha256', $path);
        $existing = Document::where('path', $path)->first();

        if ($existing && $existing->content_hash === $contentHash) {
            return $existing;
        }

        $document = $existing ?? new Document;
        $document->fill([
            'name' => basename($path),
            'path' => $path,
            'file_type' => strtolower(pathinfo($path, PATHINFO_EXTENSION)),
            'file_size' => $fileSize,
            'content_hash' => $contentHash,
            'status' => 'processing',
        ]);
        $document->save();

        if ($existing) {
            $this->deleteChunksAndEmbeddings($document);
        }

        try {
            $rawChunks = $this->chunker->chunk($path);

            if (empty($rawChunks)) {
                $document->update(['status' => 'completed', 'chunk_count' => 0]);

                return $document;
            }

            $this->processChunks($document, $rawChunks);

            $document->update([
                'status' => 'completed',
                'chunk_count' => count($rawChunks),
            ]);
        } catch (Throwable $e) {
            Log::error('Document ingestion failed', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            $document->update(['status' => 'failed']);
        }

        return $document->fresh();
    }

    private function processChunks(Document $document, array $rawChunks): void
    {
        $batches = array_chunk($rawChunks, self::BATCH_SIZE);

        foreach ($batches as $batch) {
            $texts = array_column($batch, 'content');
            $embeddings = $this->embedder->embedBatch($texts);

            foreach ($batch as $i => $rawChunk) {
                $embeddingId = null;

                if (isset($embeddings[$i])) {
                    $embeddingId = $this->vectorStore->store($embeddings[$i], [
                        'source_type' => 'document_chunk',
                        'source_id' => $document->id,
                        'content_preview' => mb_substr($rawChunk['content'], 0, 200),
                    ]);
                }

                DocumentChunk::create([
                    'document_id' => $document->id,
                    'content' => $rawChunk['content'],
                    'metadata' => $rawChunk['metadata'] ?? null,
                    'embedding_id' => $embeddingId,
                    'chunk_index' => $rawChunk['metadata']['chunk_index'] ?? 0,
                    'start_line' => $rawChunk['metadata']['start_line'] ?? null,
                    'end_line' => $rawChunk['metadata']['end_line'] ?? null,
                ]);
            }
        }
    }

    private function deleteChunksAndEmbeddings(Document $document): void
    {
        $chunks = DocumentChunk::where('document_id', $document->id)->get();

        foreach ($chunks as $chunk) {
            if ($chunk->embedding_id) {
                $this->vectorStore->delete($chunk->embedding_id);
            }
        }

        DocumentChunk::where('document_id', $document->id)->delete();
    }
}
