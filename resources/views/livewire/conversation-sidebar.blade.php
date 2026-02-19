<div class="flex flex-col flex-1 overflow-hidden">
    <div class="px-3 pt-3 pb-2">
        <button
            wire:click="createConversation"
            class="no-drag w-full flex items-center justify-center gap-2 px-3 py-2.5 rounded-lg bg-aegis-accent/10 border border-aegis-accent/20 text-aegis-accent hover:bg-aegis-accent/15 hover:border-aegis-accent/30 transition-all duration-150 text-[13px] font-semibold"
        >
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                <line x1="12" y1="5" x2="12" y2="19"/>
                <line x1="5" y1="12" x2="19" y2="12"/>
            </svg>
            New Chat
        </button>
    </div>

    <div class="px-3 pb-2">
        <div class="relative">
            <svg class="absolute left-2.5 top-1/2 -translate-y-1/2 w-3.5 h-3.5 text-aegis-text-dim pointer-events-none" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <circle cx="11" cy="11" r="8"/>
                <line x1="21" y1="21" x2="16.65" y2="16.65"/>
            </svg>
            <input
                wire:model.live.debounce.300ms="search"
                type="text"
                placeholder="Search conversations..."
                class="w-full bg-aegis-900/60 border border-aegis-border rounded-lg pl-8 pr-3 py-1.5 text-xs text-aegis-text placeholder-aegis-text-dim focus:outline-none focus:border-aegis-accent/40 transition-colors"
            />
        </div>
    </div>

    <div class="flex-1 overflow-y-auto px-3 space-y-2">

        {{-- Agents Section --}}
        <div x-data="{ open: $wire.entangle('agentsOpen') }">
            <button @click="open = !open" class="no-drag w-full flex items-center gap-1.5 pb-1 cursor-pointer group">
                <svg class="w-3 h-3 text-aegis-text-dim/60 transition-transform duration-150" :class="{ '-rotate-90': !open }" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
                <span class="text-[10px] font-medium uppercase tracking-wider text-aegis-text-dim/60 group-hover:text-aegis-text-dim transition-colors">Agents</span>
            </button>
            <div x-show="open" x-transition:enter="transition-all duration-150 ease-out" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0" class="space-y-px">
                @forelse ($agents as $agent)
                    <div
                        wire:click="openAgentConversation({{ $agent->id }})"
                        class="no-drag w-full text-left px-2.5 py-1.5 rounded-lg text-[13px] transition-all duration-150 flex items-center gap-2 cursor-pointer text-aegis-text-dim hover:text-aegis-text hover:bg-aegis-surface-hover"
                    >
                        <span class="text-base leading-none shrink-0">{{ $agent->avatar ?? '🤖' }}</span>
                        <span class="truncate flex-1">{{ $agent->name }}</span>
                    </div>
                @empty
                    <div class="px-2.5 py-2 text-center">
                        <p class="text-[11px] text-aegis-text-dim/50">No agents yet</p>
                    </div>
                @endforelse
                <a
                    href="{{ route('settings', ['tab' => 'agents']) }}"
                    class="no-drag flex items-center gap-2 px-2.5 py-1.5 rounded-lg text-[12px] text-aegis-accent/60 hover:text-aegis-accent hover:bg-aegis-accent/5 transition-all duration-150"
                >
                    <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                        <line x1="12" y1="5" x2="12" y2="19"/>
                        <line x1="5" y1="12" x2="19" y2="12"/>
                    </svg>
                    New Agent
                </a>
            </div>
        </div>

        {{-- Conversations Section --}}
        <div x-data="{ open: $wire.entangle('conversationsOpen') }">
            <button @click="open = !open" class="no-drag w-full flex items-center gap-1.5 pb-1 cursor-pointer group">
                <svg class="w-3 h-3 text-aegis-text-dim/60 transition-transform duration-150" :class="{ '-rotate-90': !open }" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
                <span class="text-[10px] font-medium uppercase tracking-wider text-aegis-text-dim/60 group-hover:text-aegis-text-dim transition-colors">Conversations</span>
            </button>
            <div x-show="open" x-transition:enter="transition-all duration-150 ease-out" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0" class="space-y-px">
                @forelse ($conversations as $conversation)
                    <div
                        wire:click="selectConversation({{ $conversation->id }})"
                        class="no-drag w-full text-left px-2.5 py-1.5 rounded-lg text-[13px] transition-all duration-150 group flex items-center justify-between gap-2 cursor-pointer
                            {{ $activeConversationId === $conversation->id
                                ? 'bg-aegis-accent/10 text-aegis-text'
                                : 'text-aegis-text-dim hover:text-aegis-text hover:bg-aegis-surface-hover' }}"
                    >
                        <span class="truncate flex-1">
                            {{ $conversation->title ?: 'New conversation' }}
                        </span>
                        <button
                            wire:click.stop="deleteConversation({{ $conversation->id }})"
                            class="opacity-0 group-hover:opacity-100 p-0.5 rounded hover:bg-red-500/20 text-aegis-text-dim hover:text-red-300 transition-all shrink-0"
                        >
                            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <polyline points="3 6 5 6 21 6"/>
                                <path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/>
                            </svg>
                        </button>
                    </div>
                @empty
                    <div class="px-2.5 py-4 text-center">
                        <p class="text-xs text-aegis-text-dim">No conversations yet</p>
                    </div>
                @endforelse
            </div>
        </div>

        {{-- Projects Section --}}
        <div x-data="{ open: $wire.entangle('projectsOpen') }">
            <button @click="open = !open" class="no-drag w-full flex items-center gap-1.5 pb-1 cursor-pointer group">
                <svg class="w-3 h-3 text-aegis-text-dim/60 transition-transform duration-150" :class="{ '-rotate-90': !open }" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <polyline points="6 9 12 15 18 9"/>
                </svg>
                <span class="text-[10px] font-medium uppercase tracking-wider text-aegis-text-dim/60 group-hover:text-aegis-text-dim transition-colors">Projects</span>
            </button>
            <div x-show="open" x-transition:enter="transition-all duration-150 ease-out" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0" class="space-y-px">
                @forelse ($projects as $project)
                    <a
                        href="{{ route('project.dashboard', $project->id) }}"
                        class="no-drag w-full text-left px-2.5 py-1.5 rounded-lg text-[13px] transition-all duration-150 flex items-center justify-between gap-2 cursor-pointer text-aegis-text-dim hover:text-aegis-text hover:bg-aegis-surface-hover"
                    >
                        <span class="truncate flex-1">{{ $project->title }}</span>
                        <span class="text-[10px] text-aegis-text-dim/50 shrink-0">{{ $project->completed_tasks_count }}/{{ $project->tasks_count }}</span>
                    </a>
                @empty
                    <div class="px-2.5 py-2 text-center">
                        <p class="text-[11px] text-aegis-text-dim/50">No projects yet</p>
                    </div>
                @endforelse
            </div>
        </div>

    </div>
</div>
