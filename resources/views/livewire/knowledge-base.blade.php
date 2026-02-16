<div class="max-w-4xl mx-auto px-6 py-8 space-y-6">
    <div class="flex items-center justify-between">
        <h2 class="text-lg font-display font-bold text-aegis-text">Knowledge Base</h2>
        <a href="{{ route('chat') }}" class="text-xs text-aegis-text-dim hover:text-aegis-text transition-colors">&larr; Back to Chat</a>
    </div>

    @if($flashMessage)
        <div class="px-4 py-2.5 rounded-lg text-sm {{ $flashType === 'error' ? 'bg-red-500/10 text-red-400 border border-red-500/20' : 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' }}">
            {{ $flashMessage }}
        </div>
    @endif

    {{-- Upload Section --}}
    <div class="bg-aegis-850 border border-aegis-border rounded-xl p-5">
        <h3 class="text-sm font-semibold text-aegis-text mb-3">Upload Document</h3>
        <form wire:submit="uploadDocument" class="space-y-3">
            <div
                x-data="{ dragging: false }"
                x-on:dragover.prevent="dragging = true"
                x-on:dragleave.prevent="dragging = false"
                x-on:drop.prevent="dragging = false"
                :class="dragging ? 'border-aegis-accent bg-aegis-accent/5' : 'border-aegis-border'"
                class="border-2 border-dashed rounded-lg p-6 text-center transition-colors"
            >
                <input type="file" wire:model="upload" class="hidden" id="file-upload"
                    accept=".php,.js,.ts,.py,.md,.txt,.jsx,.tsx,.rb,.go,.rs" />
                <label for="file-upload" class="cursor-pointer">
                    <svg class="w-8 h-8 mx-auto text-aegis-text-dim mb-2" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <path d="M21 15v4a2 2 0 01-2 2H5a2 2 0 01-2-2v-4"/>
                        <polyline points="17 8 12 3 7 8"/>
                        <line x1="12" y1="3" x2="12" y2="15"/>
                    </svg>
                    <p class="text-sm text-aegis-text-dim">Drop a file here or <span class="text-aegis-accent">browse</span></p>
                    <p class="text-xs text-aegis-text-dim/60 mt-1">Supports: PHP, JS, TS, Python, Markdown, Text (max {{ config('aegis.rag.max_file_size_mb', 10) }}MB)</p>
                </label>
            </div>

            @error('upload')
                <p class="text-xs text-red-400">{{ $message }}</p>
            @enderror

            <div wire:loading wire:target="upload" class="text-xs text-aegis-text-dim">Uploading...</div>

            @if($upload)
                <div class="flex items-center justify-between">
                    <span class="text-sm text-aegis-text">{{ $upload->getClientOriginalName() }}</span>
                    <button type="submit" class="px-3 py-1.5 bg-aegis-accent text-aegis-900 text-xs font-semibold rounded-md hover:bg-aegis-accent/90 transition-colors" wire:loading.attr="disabled">
                        <span wire:loading.remove wire:target="uploadDocument">Ingest</span>
                        <span wire:loading wire:target="uploadDocument">Processing...</span>
                    </button>
                </div>
            @endif
        </form>
    </div>

    {{-- Documents List --}}
    <div class="bg-aegis-850 border border-aegis-border rounded-xl">
        <div class="px-5 py-3 border-b border-aegis-border">
            <h3 class="text-sm font-semibold text-aegis-text">Ingested Documents</h3>
        </div>

        @if($this->documents->isEmpty())
            <div class="px-5 py-8 text-center text-sm text-aegis-text-dim">
                No documents ingested yet. Upload a file to get started.
            </div>
        @else
            <div class="divide-y divide-aegis-border">
                @foreach($this->documents as $doc)
                    <div class="px-5 py-3 flex items-center justify-between gap-4">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-aegis-text truncate">{{ $doc->name }}</p>
                            <div class="flex items-center gap-3 mt-0.5">
                                <span class="text-xs text-aegis-text-dim">{{ strtoupper($doc->file_type) }}</span>
                                <span class="text-xs text-aegis-text-dim">{{ number_format($doc->file_size / 1024, 1) }}KB</span>
                                <span class="text-xs text-aegis-text-dim">{{ $doc->chunk_count }} chunks</span>
                                <span class="inline-flex items-center gap-1 text-xs {{ $doc->status === 'completed' ? 'text-emerald-400' : ($doc->status === 'failed' ? 'text-red-400' : 'text-amber-400') }}">
                                    <span class="w-1.5 h-1.5 rounded-full {{ $doc->status === 'completed' ? 'bg-emerald-400' : ($doc->status === 'failed' ? 'bg-red-400' : 'bg-amber-400') }}"></span>
                                    {{ ucfirst($doc->status) }}
                                </span>
                            </div>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <button wire:click="reindexDocument({{ $doc->id }})" wire:loading.attr="disabled" class="p-1.5 rounded-md text-aegis-text-dim hover:text-aegis-accent hover:bg-aegis-surface-hover transition-colors" title="Re-index">
                                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="23 4 23 10 17 10"/>
                                    <path d="M20.49 15a9 9 0 11-2.12-9.36L23 10"/>
                                </svg>
                            </button>
                            <button wire:click="deleteDocument({{ $doc->id }})" wire:confirm="Delete this document and all its chunks?" class="p-1.5 rounded-md text-aegis-text-dim hover:text-red-400 hover:bg-red-500/10 transition-colors" title="Delete">
                                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                    <polyline points="3 6 5 6 21 6"/>
                                    <path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/>
                                </svg>
                            </button>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
