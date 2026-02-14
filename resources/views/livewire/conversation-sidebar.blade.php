<div class="flex flex-col flex-1 overflow-hidden">
    <div class="px-3 py-3">
        <button
            wire:click="createConversation"
            class="no-drag w-full flex items-center gap-2 px-3 py-2.5 rounded-lg bg-aegis-accent/10 border border-aegis-accent/20 text-aegis-accent hover:bg-aegis-accent/15 hover:border-aegis-accent/30 transition-all duration-150 text-sm font-medium"
        >
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="12" y1="5" x2="12" y2="19"/>
                <line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            New Chat
        </button>
    </div>

    <div class="px-3 pb-2">
        <div class="text-xs font-medium text-aegis-text-dim uppercase tracking-wider px-3 py-2">Conversations</div>
        <div class="relative">
            <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-aegis-text-dim pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="11" cy="11" r="8"/>
                <line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <input
                wire:model.live.debounce.300ms="search"
                type="text"
                placeholder="Search..."
                class="w-full bg-aegis-900/60 border border-aegis-border rounded-lg pl-8 pr-3 py-1.5 text-xs text-aegis-text placeholder-aegis-text-dim focus:outline-none focus:border-aegis-accent/40 transition-colors"
            />
        </div>
    </div>

    <div class="flex-1 overflow-y-auto space-y-0.5">
        @forelse ($conversations as $conversation)
            <button
                wire:click="selectConversation({{ $conversation->id }})"
                class="no-drag w-full text-left px-3 py-2.5 rounded-lg text-sm transition-all duration-150 group flex items-center justify-between gap-2
                    {{ $activeConversationId === $conversation->id
                        ? 'bg-aegis-accent/10 border border-aegis-accent/20 text-aegis-text'
                        : 'text-aegis-text-dim hover:text-aegis-text hover:bg-aegis-surface-hover border border-transparent' }}"
            >
                <span class="truncate flex-1">
                    {{ $conversation->title ?: 'New conversation' }}
                </span>
                <button
                    wire:click.stop="deleteConversation({{ $conversation->id }})"
                    class="opacity-0 group-hover:opacity-100 p-1 rounded hover:bg-red-500/20 text-aegis-text-dim hover:text-red-300 transition-all shrink-0"
                >
                    <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                        <polyline points="3 6 5 6 21 6"/>
                        <path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/>
                    </svg>
                </button>
            </button>
        @empty
            <div class="px-3 py-6 text-center">
                <p class="text-xs text-aegis-text-dim">No conversations yet</p>
            </div>
        @endforelse
    </div>
</div>
