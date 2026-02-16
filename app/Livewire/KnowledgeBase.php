<?php

namespace App\Livewire;

use App\Models\Document;
use App\Models\DocumentChunk;
use App\Rag\DocumentIngestionService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Laravel\Ai\Files\Document as AiDocument;
use Laravel\Ai\Stores;
use Livewire\Component;
use Livewire\WithFileUploads;
use Throwable;

class KnowledgeBase extends Component
{
    use WithFileUploads;

    public $upload;

    public string $flashMessage = '';

    public string $flashType = 'success';

    public function getDocumentsProperty(): Collection
    {
        return Document::query()
            ->orderByDesc('updated_at')
            ->get();
    }

    public function uploadDocument(): void
    {
        $this->validate([
            'upload' => [
                'required',
                'file',
                'max:'.((int) config('aegis.rag.max_file_size_mb', 10) * 1024),
            ],
        ]);

        $originalName = $this->upload->getClientOriginalName();

        Storage::disk('local')->makeDirectory('knowledge');

        $path = $this->upload->storeAs('knowledge', $originalName, 'local');
        $targetPath = Storage::disk('local')->path($path);

        $service = app(DocumentIngestionService::class);
        $document = $service->ingest($targetPath);

        if (! $document || $document->status !== 'completed') {
            $this->flash('Failed to ingest: '.$originalName, 'error');
            $this->reset('upload');

            return;
        }

        try {
            $store = Stores::create('aegis-kb-'.md5($originalName), 'Knowledge base for '.$originalName, provider: 'openai');
            $addedFile = $store->add(AiDocument::fromPath($targetPath));

            $document->update([
                'vector_store_id' => $store->id,
                'provider_file_id' => $addedFile->id,
            ]);

            $this->flash('Document uploaded: '.$originalName.' ('.$document->chunk_count.' chunks, vector store enabled)', 'success');
        } catch (Throwable $e) {
            Log::info('Vector store upload skipped, using local chunks', ['error' => $e->getMessage()]);
            $this->flash('Document ingested: '.$originalName.' ('.$document->chunk_count.' chunks)', 'success');
        }

        $this->reset('upload');
    }

    public function deleteDocument(int $id): void
    {
        $document = Document::find($id);

        if (! $document) {
            return;
        }

        if ($document->vector_store_id) {
            try {
                Stores::delete($document->vector_store_id, 'openai');
            } catch (Throwable $e) {
                Log::warning('Failed to delete vector store', ['error' => $e->getMessage()]);
            }
        }

        DocumentChunk::where('document_id', $document->id)->delete();

        if (file_exists($document->path)) {
            unlink($document->path);
        }

        $document->delete();

        $this->flash('Document deleted.', 'success');
    }

    public function reindexDocument(int $id): void
    {
        $document = Document::find($id);

        if (! $document || ! file_exists($document->path)) {
            $this->flash('Document file not found.', 'error');

            return;
        }

        if ($document->vector_store_id) {
            try {
                Stores::delete($document->vector_store_id, 'openai');
            } catch (Throwable) {
            }

            $document->update(['vector_store_id' => null, 'provider_file_id' => null]);
        }

        $document->update(['content_hash' => null]);

        $service = app(DocumentIngestionService::class);
        $result = $service->ingest($document->path);

        if (! $result || $result->status !== 'completed') {
            $this->flash('Re-indexing failed.', 'error');

            return;
        }

        try {
            $store = Stores::create('aegis-kb-'.md5($document->name), 'Knowledge base for '.$document->name, provider: 'openai');
            $addedFile = $store->add(AiDocument::fromPath($document->path));

            $result->update([
                'vector_store_id' => $store->id,
                'provider_file_id' => $addedFile->id,
            ]);

            $this->flash('Re-indexed: '.$document->name.' ('.$result->chunk_count.' chunks, vector store enabled)', 'success');
        } catch (Throwable $e) {
            Log::info('Vector store re-index skipped, using local chunks', ['error' => $e->getMessage()]);
            $this->flash('Re-indexed locally: '.$document->name.' ('.$result->chunk_count.' chunks)', 'success');
        }
    }

    public function render()
    {
        return view('livewire.knowledge-base');
    }

    private function flash(string $message, string $type = 'success'): void
    {
        $this->flashMessage = $message;
        $this->flashType = $type;
    }
}
