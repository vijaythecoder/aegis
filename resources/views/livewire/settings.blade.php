<div class="max-w-4xl mx-auto px-6 py-8 space-y-8">

    {{-- Header --}}
    <div class="flex items-start justify-between gap-4">
        <div>
            <h2 class="font-display font-bold text-2xl tracking-tight text-aegis-text">Settings</h2>
            <p class="mt-1 text-sm text-aegis-text-dim">Manage providers, security, and application preferences.</p>
        </div>
        <a href="{{ route('chat') }}" class="shrink-0 flex items-center gap-1.5 px-3 py-2 rounded-lg text-sm text-aegis-text-dim hover:text-aegis-text hover:bg-aegis-surface-hover border border-aegis-border transition-all duration-150">
            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                <line x1="19" y1="12" x2="5" y2="12"/>
                <polyline points="12 19 5 12 12 5"/>
            </svg>
            Back to Chat
        </a>
    </div>

    {{-- Flash Message --}}
    @if ($flashMessage)
        <div
            x-data="{ show: true }"
            x-init="setTimeout(() => show = false, 4000)"
            x-show="show"
            x-transition:leave="transition ease-in duration-200"
            x-transition:leave-start="opacity-100"
            x-transition:leave-end="opacity-0"
            @class([
                'px-4 py-3 rounded-xl text-sm border',
                'bg-emerald-500/10 border-emerald-400/20 text-emerald-300' => $flashType === 'success',
                'bg-red-500/10 border-red-400/20 text-red-300' => $flashType === 'error',
            ])
        >
            {{ $flashMessage }}
        </div>
    @endif

    {{-- Tab Navigation --}}
    <div x-data="{ tab: $wire.entangle('activeTab') }" class="space-y-6">
        <nav class="flex gap-1 p-1 rounded-xl bg-aegis-850 border border-aegis-border">
            @foreach (['providers' => 'Providers', 'memory' => 'Memory', 'automation' => 'Automation', 'marketplace' => 'Marketplace', 'security' => 'Security', 'general' => 'General'] as $key => $label)
                <button
                    type="button"
                    wire:click="setTab('{{ $key }}')"
                    @class([
                        'flex-1 px-4 py-2.5 rounded-lg text-sm font-medium transition-all duration-150',
                        'bg-aegis-accent/15 text-aegis-accent border border-aegis-accent/20 shadow-sm' => $activeTab === $key,
                        'text-aegis-text-dim hover:text-aegis-text hover:bg-aegis-surface-hover' => $activeTab !== $key,
                    ])
                >
                    {{ $label }}
                </button>
            @endforeach
        </nav>

        {{-- ═══ Providers Tab ═══ --}}
        @if ($activeTab === 'providers')
            <div class="space-y-8" x-data="{ editingProvider: null }">

                <div>
                    <h3 class="text-lg font-display font-bold text-aegis-text">API Providers</h3>
                    <p class="text-sm text-aegis-text-dim mt-1">Configure API keys for each AI provider.</p>
                </div>

                <div class="space-y-3">
                    @foreach ($providers as $providerId => $provider)
                        <div class="rounded-xl border border-aegis-border bg-aegis-850 p-5">
                            <div class="flex items-center justify-between">
                                <div class="flex items-center gap-3">
                                    <div @class([
                                        'w-2.5 h-2.5 rounded-full',
                                        'bg-emerald-400 shadow-sm shadow-emerald-400/40' => $provider['is_set'] || !$provider['requires_key'],
                                        'bg-aegis-600' => !$provider['is_set'] && $provider['requires_key'],
                                    ])></div>
                                    <div>
                                        <p class="text-sm font-medium text-aegis-text">{{ $provider['name'] }}</p>
                                        <p class="text-xs text-aegis-text-dim mt-0.5">
                                            @if ($provider['is_set'])
                                                <span class="text-emerald-400">Configured</span>
                                                <span class="ml-2 font-mono text-aegis-text-dim">{{ $provider['masked'] }}</span>
                                            @elseif (!$provider['requires_key'])
                                                <span class="text-emerald-400">Configured</span>
                                                <span class="ml-2 text-aegis-text-dim">No key required</span>
                                            @else
                                                <span class="text-aegis-text-dim">Not configured</span>
                                            @endif
                                        </p>
                                        @php($status = $providerStatus[$providerId] ?? ['available' => false, 'rate_limited' => false, 'models' => []])
                                        <p class="text-xs text-aegis-text-dim mt-1">
                                            @if ($status['available'])
                                                <span class="text-emerald-400">Available</span>
                                            @else
                                                <span class="text-red-300">Unavailable</span>
                                            @endif

                                            @if ($status['rate_limited'])
                                                <span class="ml-2 text-amber-300">Rate limited</span>
                                            @endif

                                            @if (($status['models'] ?? []) !== [])
                                                <span class="ml-2">{{ count($status['models']) }} models</span>
                                            @endif
                                        </p>
                                    </div>
                                </div>

                                <div class="flex items-center gap-2">
                                    @if ($provider['is_set'])
                                        <button
                                            type="button"
                                            wire:click="deleteApiKey('{{ $providerId }}')"
                                            wire:confirm="Remove API key for {{ $provider['name'] }}?"
                                            class="px-3 py-1.5 rounded-lg text-xs font-medium text-red-300 border border-red-400/20 bg-red-500/5 hover:bg-red-500/15 transition-colors"
                                        >
                                            Remove
                                        </button>
                                    @endif
                                    @if ($provider['requires_key'])
                                        <button
                                            type="button"
                                            @click="editingProvider = editingProvider === '{{ $providerId }}' ? null : '{{ $providerId }}'"
                                            class="px-3 py-1.5 rounded-lg text-xs font-medium text-aegis-text-dim border border-aegis-border bg-aegis-surface hover:bg-aegis-surface-hover transition-colors"
                                        >
                                            {{ $provider['is_set'] ? 'Update' : 'Set Key' }}
                                        </button>
                                    @endif
                                </div>
                            </div>

                            @if ($provider['requires_key'])
                                <div
                                    x-show="editingProvider === '{{ $providerId }}'"
                                    x-transition:enter="transition ease-out duration-150"
                                    x-transition:enter-start="opacity-0 -translate-y-1"
                                    x-transition:enter-end="opacity-100 translate-y-0"
                                    class="mt-4 pt-4 border-t border-aegis-border"
                                >
                                    <div class="flex gap-2">
                                        <input
                                            type="password"
                                            wire:model="apiKeyInput"
                                            placeholder="Enter API key…"
                                            class="flex-1 px-3 py-2 rounded-lg bg-aegis-900 border border-aegis-border text-sm text-aegis-text placeholder:text-aegis-text-dim/40 focus:outline-none focus:border-aegis-accent/40 focus:ring-1 focus:ring-aegis-accent/20 transition-colors"
                                        />
                                        <button
                                            type="button"
                                            wire:click="testConnection('{{ $providerId }}')"
                                            class="px-3 py-2 rounded-lg text-xs font-medium text-aegis-text-dim border border-aegis-border bg-aegis-surface hover:bg-aegis-surface-hover transition-colors"
                                        >
                                            Test
                                        </button>
                                        <button
                                            type="button"
                                            wire:click="saveApiKey('{{ $providerId }}')"
                                            @click="editingProvider = null"
                                            class="px-4 py-2 rounded-lg text-xs font-semibold text-aegis-accent border border-aegis-accent/30 bg-aegis-accent/10 hover:bg-aegis-accent/20 transition-colors"
                                        >
                                            Save
                                        </button>
                                    </div>
                                </div>
                            @endif
                        </div>
                    @endforeach
                </div>

                {{-- Default Provider & Model --}}
                <div class="rounded-xl border border-aegis-border bg-aegis-850 p-5 space-y-4">
                    <h4 class="text-sm font-semibold text-aegis-text">Defaults</h4>

                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                        <div>
                            <label class="block text-xs font-medium text-aegis-text-dim mb-1.5">Default Provider</label>
                            <select
                                wire:model.live="defaultProvider"
                                class="w-full px-3 py-2 rounded-lg bg-aegis-900 border border-aegis-border text-sm text-aegis-text focus:outline-none focus:border-aegis-accent/40 focus:ring-1 focus:ring-aegis-accent/20 transition-colors"
                            >
                                @foreach ($configuredProviders as $id => $name)
                                    <option value="{{ $id }}">{{ $name }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-aegis-text-dim mb-1.5">Default Model</label>
                            <select
                                wire:model="defaultModel"
                                class="w-full px-3 py-2 rounded-lg bg-aegis-900 border border-aegis-border text-sm text-aegis-text focus:outline-none focus:border-aegis-accent/40 focus:ring-1 focus:ring-aegis-accent/20 transition-colors"
                            >
                                @foreach ($this->availableModels() as $modelId)
                                    <option value="{{ $modelId }}">{{ $modelId }}</option>
                                @endforeach
                                @if ($this->availableModels() === [])
                                    <option value="" disabled>No models available</option>
                                @endif
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-aegis-text-dim mb-1.5">Model Role</label>
                            <select
                                wire:model="modelRole"
                                class="w-full px-3 py-2 rounded-lg bg-aegis-900 border border-aegis-border text-sm text-aegis-text focus:outline-none focus:border-aegis-accent/40 focus:ring-1 focus:ring-aegis-accent/20 transition-colors"
                            >
                                <option value="default">Default</option>
                                <option value="smartest">Smartest</option>
                                <option value="cheapest">Cheapest</option>
                            </select>
                            <p class="text-[11px] text-aegis-text-dim mt-1">SDK auto-selects the best model for this role.</p>
                        </div>
                    </div>

                    <div class="flex justify-end">
                        <button
                            type="button"
                            wire:click="saveDefaults"
                            class="px-4 py-2 rounded-lg text-xs font-semibold text-aegis-accent border border-aegis-accent/30 bg-aegis-accent/10 hover:bg-aegis-accent/20 transition-colors"
                        >
                            Save Defaults
                        </button>
                    </div>
                </div>
            </div>
        @endif

        {{-- ═══ Memory & Embeddings Tab ═══ --}}
        @if ($activeTab === 'memory')
            <div class="space-y-8">

                <div>
                    <h3 class="text-lg font-display font-bold text-aegis-text">Memory & Embeddings</h3>
                    <p class="text-sm text-aegis-text-dim mt-1">Configure how Aegis generates and stores semantic embeddings for cross-conversation memory.</p>
                </div>

                <div class="rounded-xl border border-aegis-border bg-aegis-850 p-5 space-y-4">
                    <h4 class="text-sm font-semibold text-aegis-text">Embedding Provider</h4>

                    <div class="space-y-4">
                        <div>
                            <label class="block text-xs font-medium text-aegis-text-dim mb-1.5">Provider</label>
                            <select
                                wire:model.live="embeddingProvider"
                                class="w-full px-3 py-2 rounded-lg bg-aegis-900 border border-aegis-border text-sm text-aegis-text focus:outline-none focus:border-aegis-accent/40 focus:ring-1 focus:ring-aegis-accent/20 transition-colors"
                            >
                                <option value="ollama">Ollama (Local)</option>
                                <option value="openai">OpenAI (Cloud)</option>
                                <option value="disabled">Disabled</option>
                            </select>
                        </div>

                        @if ($embeddingProvider !== 'disabled')
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-medium text-aegis-text-dim mb-1.5">Model</label>
                                    <input
                                        type="text"
                                        wire:model="embeddingModel"
                                        placeholder="{{ $embeddingProvider === 'ollama' ? 'nomic-embed-text' : 'text-embedding-3-small' }}"
                                        class="w-full px-3 py-2 rounded-lg bg-aegis-900 border border-aegis-border text-sm text-aegis-text placeholder:text-aegis-text-dim/40 focus:outline-none focus:border-aegis-accent/40 focus:ring-1 focus:ring-aegis-accent/20 transition-colors"
                                    />
                                </div>
                                <div>
                                    <label class="block text-xs font-medium text-aegis-text-dim mb-1.5">Dimensions</label>
                                    <input
                                        type="number"
                                        wire:model="embeddingDimensions"
                                        min="64"
                                        max="4096"
                                        class="w-full px-3 py-2 rounded-lg bg-aegis-900 border border-aegis-border text-sm text-aegis-text focus:outline-none focus:border-aegis-accent/40 focus:ring-1 focus:ring-aegis-accent/20 transition-colors"
                                    />
                                </div>
                            </div>
                        @endif

                        @if ($embeddingProvider === 'disabled')
                            <div class="rounded-lg border border-amber-400/20 bg-amber-500/5 px-4 py-3 text-sm text-amber-300">
                                Embeddings are disabled. Memory search will use keyword matching only (FTS5).
                            </div>
                        @endif

                        @if ($embeddingProvider === 'ollama')
                            <div class="rounded-lg border border-aegis-border bg-aegis-900/50 px-4 py-3 text-xs text-aegis-text-dim space-y-1">
                                <p>Ollama runs locally on your machine. Make sure it's installed and running.</p>
                                <p class="font-mono">ollama pull {{ $embeddingModel ?: 'nomic-embed-text' }}</p>
                            </div>
                        @endif
                    </div>

                    <div class="flex items-center justify-end gap-2 pt-2">
                        <button
                            type="button"
                            wire:click="testEmbeddingConnection"
                            class="px-3 py-2 rounded-lg text-xs font-medium text-aegis-text-dim border border-aegis-border bg-aegis-surface hover:bg-aegis-surface-hover transition-colors"
                        >
                            Test Connection
                        </button>
                        <button
                            type="button"
                            wire:click="saveEmbeddingSettings"
                            class="px-4 py-2 rounded-lg text-xs font-semibold text-aegis-accent border border-aegis-accent/30 bg-aegis-accent/10 hover:bg-aegis-accent/20 transition-colors"
                        >
                            Save Settings
                        </button>
                    </div>
                </div>

                {{-- User Profile Preview --}}
                @if ($userProfile)
                    <div class="rounded-xl border border-aegis-border bg-aegis-850 p-5 space-y-3">
                        <div class="flex items-center justify-between">
                            <h4 class="text-sm font-semibold text-aegis-text">User Profile (Layer 0)</h4>
                            <button
                                type="button"
                                wire:click="refreshUserProfile"
                                class="px-3 py-1.5 rounded-lg text-xs font-medium text-aegis-text-dim border border-aegis-border bg-aegis-surface hover:bg-aegis-surface-hover transition-colors"
                            >
                                Refresh
                            </button>
                        </div>
                        <p class="text-xs text-aegis-text-dim leading-relaxed whitespace-pre-line">{{ $userProfile }}</p>
                    </div>
                @endif

                {{-- Stored Memories --}}
                <div class="rounded-xl border border-aegis-border bg-aegis-850 p-5 space-y-4">
                    <div class="flex items-center justify-between">
                        <div>
                            <h4 class="text-sm font-semibold text-aegis-text">Stored Memories</h4>
                            <p class="text-xs text-aegis-text-dim mt-0.5">{{ $memories->count() }} memories stored</p>
                        </div>
                    </div>

                    @if ($memories->isEmpty())
                        <p class="text-sm text-aegis-text-dim py-4 text-center">No memories stored yet. Chat with Aegis and it will remember important details.</p>
                    @else
                        <div class="space-y-2 max-h-96 overflow-y-auto">
                            @foreach ($memories as $memory)
                                <div class="flex items-start justify-between gap-3 rounded-lg border border-aegis-border bg-aegis-900/50 px-3 py-2.5">
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center gap-2">
                                            <span class="inline-flex items-center rounded-full px-2 py-0.5 text-[10px] font-medium
                                                {{ $memory->type->value === 'fact' ? 'bg-blue-500/10 text-blue-400' : '' }}
                                                {{ $memory->type->value === 'preference' ? 'bg-purple-500/10 text-purple-400' : '' }}
                                                {{ $memory->type->value === 'note' ? 'bg-amber-500/10 text-amber-400' : '' }}
                                            ">{{ $memory->type->value }}</span>
                                            <span class="text-xs font-mono text-aegis-text-dim truncate">{{ $memory->key }}</span>
                                            <span class="text-[10px] text-aegis-text-dim/50">{{ number_format($memory->confidence, 2) }}</span>
                                        </div>
                                        <p class="text-sm text-aegis-text mt-1 break-words">{{ $memory->value }}</p>
                                        @if ($memory->previous_value)
                                            <p class="text-[10px] text-aegis-text-dim/50 mt-0.5 line-through">{{ $memory->previous_value }}</p>
                                        @endif
                                    </div>
                                    <button
                                        type="button"
                                        wire:click="deleteMemory({{ $memory->id }})"
                                        wire:confirm="Delete this memory?"
                                        class="shrink-0 text-red-400/60 hover:text-red-400 transition-colors p-1"
                                    >
                                        <svg class="w-3.5 h-3.5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                                        </svg>
                                    </button>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        @endif

        {{-- ═══ Automation Tab ═══ --}}
        @if ($activeTab === 'automation')
            <div class="space-y-8">

                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-display font-bold text-aegis-text">Proactive Tasks</h3>
                        <p class="text-sm text-aegis-text-dim mt-1">Schedule tasks that Aegis runs automatically on a cron schedule.</p>
                    </div>
                    <button
                        type="button"
                        wire:click="newTask"
                        class="px-4 py-2 rounded-lg text-xs font-semibold text-aegis-accent border border-aegis-accent/30 bg-aegis-accent/10 hover:bg-aegis-accent/20 transition-colors"
                    >
                        New Task
                    </button>
                </div>

                {{-- Task Form --}}
                @if ($editingTaskId !== null || ($taskName !== '' || $taskSchedule !== '' || $taskPrompt !== ''))
                    <div class="rounded-xl border border-aegis-accent/20 bg-aegis-850 p-5 space-y-4">
                        <h4 class="text-sm font-semibold text-aegis-text">
                            {{ $editingTaskId ? 'Edit Task' : 'New Task' }}
                        </h4>

                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-medium text-aegis-text-dim mb-1.5">Name</label>
                                <input
                                    type="text"
                                    wire:model="taskName"
                                    placeholder="Morning Briefing"
                                    class="w-full px-3 py-2 rounded-lg bg-aegis-900 border border-aegis-border text-sm text-aegis-text placeholder:text-aegis-text-dim/40 focus:outline-none focus:border-aegis-accent/40 focus:ring-1 focus:ring-aegis-accent/20 transition-colors"
                                />
                                @error('taskName') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="block text-xs font-medium text-aegis-text-dim mb-1.5">Schedule (Cron)</label>
                                <input
                                    type="text"
                                    wire:model="taskSchedule"
                                    placeholder="0 8 * * 1-5"
                                    class="w-full px-3 py-2 rounded-lg bg-aegis-900 border border-aegis-border text-sm text-aegis-text placeholder:text-aegis-text-dim/40 focus:outline-none focus:border-aegis-accent/40 focus:ring-1 focus:ring-aegis-accent/20 font-mono transition-colors"
                                />
                                @error('taskSchedule') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-aegis-text-dim mb-1.5">Delivery Channel</label>
                            <select
                                wire:model="taskDeliveryChannel"
                                class="w-full px-3 py-2 rounded-lg bg-aegis-900 border border-aegis-border text-sm text-aegis-text focus:outline-none focus:border-aegis-accent/40 focus:ring-1 focus:ring-aegis-accent/20 transition-colors"
                            >
                                <option value="chat">Chat</option>
                                <option value="telegram">Telegram</option>
                                <option value="notification">Notification</option>
                            </select>
                            @error('taskDeliveryChannel') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-aegis-text-dim mb-1.5">Prompt</label>
                            <textarea
                                wire:model="taskPrompt"
                                rows="3"
                                placeholder="Give me a morning briefing..."
                                class="w-full px-3 py-2 rounded-lg bg-aegis-900 border border-aegis-border text-sm text-aegis-text placeholder:text-aegis-text-dim/40 focus:outline-none focus:border-aegis-accent/40 focus:ring-1 focus:ring-aegis-accent/20 resize-y transition-colors"
                            ></textarea>
                            @error('taskPrompt') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
                        </div>

                        <div class="flex items-center justify-end gap-2">
                            <button
                                type="button"
                                wire:click="cancelTaskEdit"
                                class="px-3 py-2 rounded-lg text-xs font-medium text-aegis-text-dim border border-aegis-border bg-aegis-surface hover:bg-aegis-surface-hover transition-colors"
                            >
                                Cancel
                            </button>
                            <button
                                type="button"
                                wire:click="saveTask"
                                class="px-4 py-2 rounded-lg text-xs font-semibold text-aegis-accent border border-aegis-accent/30 bg-aegis-accent/10 hover:bg-aegis-accent/20 transition-colors"
                            >
                                {{ $editingTaskId ? 'Update' : 'Create' }}
                            </button>
                        </div>
                    </div>
                @endif

                {{-- Task List --}}
                @if ($proactiveTasks->isEmpty())
                    <div class="rounded-xl border border-aegis-border bg-aegis-850 p-6 text-center">
                        <p class="text-sm text-aegis-text-dim">No proactive tasks configured. Create one or run the seeder.</p>
                    </div>
                @else
                    <div class="space-y-3">
                        @foreach ($proactiveTasks as $task)
                            <div class="rounded-xl border border-aegis-border bg-aegis-850 p-5">
                                <div class="flex items-center justify-between gap-4">
                                    <div class="flex items-center gap-3 min-w-0 flex-1">
                                        <button
                                            type="button"
                                            wire:click="toggleTask({{ $task->id }})"
                                            @class([
                                                'relative inline-flex h-5 w-9 shrink-0 cursor-pointer rounded-full border-2 border-transparent transition-colors duration-200 ease-in-out focus:outline-none',
                                                'bg-aegis-accent' => $task->is_active,
                                                'bg-aegis-600' => !$task->is_active,
                                            ])
                                        >
                                            <span
                                                @class([
                                                    'pointer-events-none inline-block h-4 w-4 rounded-full bg-white shadow transform ring-0 transition duration-200 ease-in-out',
                                                    'translate-x-4' => $task->is_active,
                                                    'translate-x-0' => !$task->is_active,
                                                ])
                                            ></span>
                                        </button>
                                        <div class="min-w-0 flex-1">
                                            <div class="flex items-center gap-2">
                                                <p class="text-sm font-medium text-aegis-text">{{ $task->name }}</p>
                                                <span class="text-[10px] font-mono px-1.5 py-0.5 rounded bg-aegis-800 text-aegis-text-dim border border-aegis-border">{{ $task->schedule }}</span>
                                                <span @class([
                                                    'text-[10px] px-1.5 py-0.5 rounded-full font-medium border',
                                                    'bg-emerald-500/10 text-emerald-400 border-emerald-400/20' => $task->delivery_channel === 'chat',
                                                    'bg-blue-500/10 text-blue-400 border-blue-400/20' => $task->delivery_channel === 'telegram',
                                                    'bg-purple-500/10 text-purple-400 border-purple-400/20' => $task->delivery_channel === 'notification',
                                                ])>{{ $task->delivery_channel }}</span>
                                            </div>
                                            <p class="text-xs text-aegis-text-dim mt-1 truncate">{{ $task->prompt }}</p>
                                            @if ($task->last_run_at)
                                                <p class="text-[10px] text-aegis-text-dim/50 mt-1">Last run: {{ $task->last_run_at->diffForHumans() }}</p>
                                            @endif
                                        </div>
                                    </div>

                                    <div class="flex items-center gap-2 shrink-0">
                                        <button
                                            type="button"
                                            wire:click="editTask({{ $task->id }})"
                                            class="px-3 py-1.5 rounded-lg text-xs font-medium text-aegis-text-dim border border-aegis-border bg-aegis-surface hover:bg-aegis-surface-hover transition-colors"
                                        >
                                            Edit
                                        </button>
                                        <button
                                            type="button"
                                            wire:click="deleteTask({{ $task->id }})"
                                            wire:confirm="Delete task &quot;{{ $task->name }}&quot;?"
                                            class="px-3 py-1.5 rounded-lg text-xs font-medium text-red-300 border border-red-400/20 bg-red-500/5 hover:bg-red-500/15 transition-colors"
                                        >
                                            Delete
                                        </button>
                                    </div>
                                </div>
                            </div>
                        @endforeach
                    </div>
                @endif
            </div>
        @endif

        {{-- ═══ Marketplace Tab ═══ --}}
        @if ($activeTab === 'marketplace')
            <div class="space-y-8">
                <div class="flex items-center justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-display font-bold text-aegis-text">Plugin Marketplace</h3>
                        <p class="text-sm text-aegis-text-dim mt-1">Browse and install plugins from the Aegis registry.</p>
                    </div>
                    @if ($marketplaceEnabled)
                        <button
                            type="button"
                            wire:click="refreshMarketplace"
                            class="px-4 py-2 rounded-lg text-xs font-semibold text-aegis-accent border border-aegis-accent/30 bg-aegis-accent/10 hover:bg-aegis-accent/20 transition-colors"
                        >
                            Refresh
                        </button>
                    @endif
                </div>

                @if (! $marketplaceEnabled)
                    <div class="rounded-xl border border-red-400/30 bg-red-500/10 p-4 text-sm text-red-300">
                        Marketplace is currently disabled in configuration.
                    </div>
                @else
                    <div class="rounded-xl border border-aegis-border bg-aegis-850 p-5 space-y-4">
                        <div class="flex items-center justify-between gap-3">
                            <h4 class="text-sm font-semibold text-aegis-text">Browse Plugins</h4>
                            <input
                                type="text"
                                wire:model.live.debounce.300ms="marketplaceQuery"
                                placeholder="Search plugins..."
                                class="w-full max-w-sm px-3 py-2 rounded-lg bg-aegis-900 border border-aegis-border text-sm text-aegis-text placeholder:text-aegis-text-dim/40 focus:outline-none focus:border-aegis-accent/40 focus:ring-1 focus:ring-aegis-accent/20 transition-colors"
                            />
                        </div>

                        @if ($marketplacePlugins->isEmpty())
                            <div class="rounded-lg border border-aegis-border bg-aegis-900/60 px-4 py-5 text-sm text-aegis-text-dim text-center">
                                No marketplace plugins found.
                            </div>
                        @else
                            <div class="grid grid-cols-1 gap-3">
                                @foreach ($marketplacePlugins as $plugin)
                                    <div class="rounded-xl border border-aegis-border bg-aegis-900/50 p-4 space-y-3">
                                        <div class="flex items-start justify-between gap-3">
                                            <div>
                                                <div class="flex items-center gap-2">
                                                    <p class="text-sm font-semibold text-aegis-text">{{ $plugin->name }}</p>
                                                    @php($trust = $plugin->trust_tier)
                                                    <span @class([
                                                        'text-[11px] px-2 py-0.5 rounded-full font-semibold border',
                                                        'text-emerald-300 border-emerald-400/30 bg-emerald-500/10' => $trust === 'verified',
                                                        'text-amber-300 border-amber-400/30 bg-amber-500/10' => $trust === 'community',
                                                        'text-red-300 border-red-400/30 bg-red-500/10' => $trust === 'unverified',
                                                    ])>
                                                        {{ $plugin->trustTierLabel() }}
                                                    </span>
                                                </div>
                                                <p class="text-xs text-aegis-text-dim mt-1">{{ $plugin->description }}</p>
                                                <p class="text-xs text-aegis-text-dim mt-2">By {{ $plugin->author ?: 'Unknown' }} · v{{ $plugin->version }}</p>
                                            </div>

                                            <div class="flex items-center gap-2 shrink-0">
                                                <button
                                                    type="button"
                                                    wire:click="installMarketplacePlugin('{{ $plugin->name }}')"
                                                    class="px-3 py-1.5 rounded-lg text-xs font-semibold text-aegis-accent border border-aegis-accent/30 bg-aegis-accent/10 hover:bg-aegis-accent/20 transition-colors"
                                                >
                                                    Install
                                                </button>
                                            </div>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>

                    <div class="rounded-xl border border-aegis-border bg-aegis-850 p-5 space-y-4">
                        <h4 class="text-sm font-semibold text-aegis-text">Installed Plugins</h4>

                        @if ($installedPlugins->isEmpty())
                            <div class="rounded-lg border border-aegis-border bg-aegis-900/60 px-4 py-5 text-sm text-aegis-text-dim text-center">
                                No installed plugins.
                            </div>
                        @else
                            <div class="space-y-3">
                                @foreach ($installedPlugins as $plugin)
                                    <div class="rounded-lg border border-aegis-border bg-aegis-900/50 p-4 flex items-center justify-between gap-3">
                                        <div>
                                            <p class="text-sm font-semibold text-aegis-text">{{ $plugin->name }}</p>
                                            <p class="text-xs text-aegis-text-dim mt-1">v{{ $plugin->version }}</p>
                                            @if (isset($marketplaceUpdates[$plugin->name]))
                                                <p class="text-xs text-amber-300 mt-1">
                                                    Update available: {{ $marketplaceUpdates[$plugin->name]['latest_version'] }}
                                                </p>
                                            @endif
                                        </div>

                                        <div class="flex items-center gap-2 shrink-0">
                                            @if (isset($marketplaceUpdates[$plugin->name]))
                                                <button
                                                    type="button"
                                                    wire:click="updateMarketplacePlugin('{{ $plugin->name }}')"
                                                    class="px-3 py-1.5 rounded-lg text-xs font-medium text-amber-300 border border-amber-400/25 bg-amber-500/5 hover:bg-amber-500/15 transition-colors"
                                                >
                                                    Update
                                                </button>
                                            @endif
                                            <button
                                                type="button"
                                                wire:click="removeMarketplacePlugin('{{ $plugin->name }}')"
                                                class="px-3 py-1.5 rounded-lg text-xs font-medium text-red-300 border border-red-400/20 bg-red-500/5 hover:bg-red-500/15 transition-colors"
                                            >
                                                Uninstall
                                            </button>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </div>
                @endif
            </div>
        @endif

        {{-- ═══ Security Tab ═══ --}}
        @if ($activeTab === 'security')
            <div class="space-y-8">

                {{-- Tool Permissions --}}
                <div>
                    <h3 class="text-lg font-display font-bold text-aegis-text">Tool Permissions</h3>
                    <p class="text-sm text-aegis-text-dim mt-1">Remembered permission overrides for tools.</p>
                </div>

                @if ($toolPermissions->isEmpty())
                    <div class="rounded-xl border border-aegis-border bg-aegis-850 p-6 text-center">
                        <p class="text-sm text-aegis-text-dim">No saved permissions yet.</p>
                    </div>
                @else
                    <div class="rounded-xl border border-aegis-border bg-aegis-850 divide-y divide-aegis-border overflow-hidden">
                        @foreach ($toolPermissions as $perm)
                            <div class="flex items-center justify-between px-5 py-3">
                                <div class="flex items-center gap-3">
                                    <div @class([
                                        'w-2 h-2 rounded-full',
                                        'bg-emerald-400' => $perm->isAllowed(),
                                        'bg-red-400' => !$perm->isAllowed(),
                                    ])></div>
                                    <div>
                                        <p class="text-sm font-medium text-aegis-text font-mono">{{ $perm->tool_name }}</p>
                                        <p class="text-xs text-aegis-text-dim">
                                            {{ $perm->permission->value }} · {{ $perm->scope ?? 'global' }}
                                            @if ($perm->isExpired())
                                                <span class="text-amber-400 ml-1">expired</span>
                                            @endif
                                        </p>
                                    </div>
                                </div>
                                <button
                                    type="button"
                                    wire:click="deletePermission({{ $perm->id }})"
                                    class="p-1.5 rounded-lg text-aegis-text-dim hover:text-red-300 hover:bg-red-500/10 transition-colors"
                                >
                                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <polyline points="3 6 5 6 21 6"/>
                                        <path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/>
                                    </svg>
                                </button>
                            </div>
                        @endforeach
                    </div>
                @endif

                {{-- Allowed Directories --}}
                <div>
                    <h4 class="text-sm font-semibold text-aegis-text mb-2">Allowed Directories</h4>
                    <div class="rounded-xl border border-aegis-border bg-aegis-850 divide-y divide-aegis-border overflow-hidden">
                        @forelse ($allowedPaths as $path)
                            <div class="px-5 py-3">
                                <p class="text-sm font-mono text-aegis-text-dim">{{ $path }}</p>
                            </div>
                        @empty
                            <div class="px-5 py-3 text-center">
                                <p class="text-sm text-aegis-text-dim">No paths configured.</p>
                            </div>
                        @endforelse
                    </div>
                </div>

                {{-- Blocked Commands --}}
                <div>
                    <h4 class="text-sm font-semibold text-aegis-text mb-2">Blocked Commands</h4>
                    <div class="rounded-xl border border-aegis-border bg-aegis-850 divide-y divide-aegis-border overflow-hidden">
                        @forelse ($blockedCommands as $cmd)
                            <div class="px-5 py-3">
                                <p class="text-sm font-mono text-red-300/80">{{ $cmd }}</p>
                            </div>
                        @empty
                            <div class="px-5 py-3 text-center">
                                <p class="text-sm text-aegis-text-dim">No blocked commands.</p>
                            </div>
                        @endforelse
                    </div>
                </div>

                {{-- Audit Log --}}
                <div>
                    <h4 class="text-sm font-semibold text-aegis-text mb-2">Recent Audit Log</h4>
                    @if ($auditLogs->isEmpty())
                        <div class="rounded-xl border border-aegis-border bg-aegis-850 p-6 text-center">
                            <p class="text-sm text-aegis-text-dim">No audit log entries yet.</p>
                        </div>
                    @else
                        <div class="rounded-xl border border-aegis-border bg-aegis-850 divide-y divide-aegis-border overflow-hidden max-h-96 overflow-y-auto">
                            @foreach ($auditLogs as $log)
                                <div class="px-5 py-3 flex items-center justify-between gap-4">
                                    <div class="min-w-0 flex-1">
                                        <div class="flex items-center gap-2">
                                            <span class="text-sm font-medium text-aegis-text">{{ $log->action }}</span>
                                            @if ($log->tool_name)
                                                <span class="text-xs font-mono px-1.5 py-0.5 rounded bg-aegis-800 text-aegis-text-dim">{{ $log->tool_name }}</span>
                                            @endif
                                        </div>
                                    </div>
                                    <div class="flex items-center gap-3 shrink-0">
                                        <span @class([
                                            'text-xs font-medium px-2 py-0.5 rounded-full',
                                            'bg-emerald-500/10 text-emerald-400' => $log->result?->value === 'allowed',
                                            'bg-red-500/10 text-red-400' => $log->result?->value === 'denied',
                                            'bg-amber-500/10 text-amber-400' => $log->result?->value === 'pending',
                                            'bg-red-500/10 text-red-300' => $log->result?->value === 'error',
                                        ])>
                                            {{ $log->result?->value ?? '—' }}
                                        </span>
                                        <span class="text-xs text-aegis-text-dim tabular-nums">{{ $log->created_at->diffForHumans() }}</span>
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>
        @endif

        {{-- ═══ General Tab ═══ --}}
        @if ($activeTab === 'general')
            <div class="space-y-8">

                <div>
                    <h3 class="text-lg font-display font-bold text-aegis-text">General</h3>
                    <p class="text-sm text-aegis-text-dim mt-1">Application preferences and data management.</p>
                </div>

                {{-- Theme --}}
                <div class="rounded-xl border border-aegis-border bg-aegis-850 p-5">
                    <div class="flex items-center justify-between">
                        <div>
                            <h4 class="text-sm font-semibold text-aegis-text">Theme</h4>
                            <p class="text-xs text-aegis-text-dim mt-0.5">Current appearance setting.</p>
                        </div>
                        <span class="px-3 py-1.5 rounded-lg text-xs font-medium text-aegis-text bg-aegis-800 border border-aegis-border">
                            Dark
                        </span>
                    </div>
                </div>

                {{-- Data Management --}}
                <div class="rounded-xl border border-aegis-border bg-aegis-850 p-5 space-y-4">
                    <h4 class="text-sm font-semibold text-aegis-text">Data Management</h4>

                    <div class="space-y-3">
                        <div class="flex items-center justify-between py-2">
                            <div>
                                <p class="text-sm text-aegis-text">Clear Memories</p>
                                <p class="text-xs text-aegis-text-dim">Remove all stored facts and preferences.</p>
                            </div>
                            <button
                                type="button"
                                wire:click="clearMemories"
                                wire:confirm="This will permanently delete all saved memories. Continue?"
                                class="px-3 py-1.5 rounded-lg text-xs font-medium text-amber-300 border border-amber-400/20 bg-amber-500/5 hover:bg-amber-500/15 transition-colors"
                            >
                                Clear Memories
                            </button>
                        </div>

                        <div class="border-t border-aegis-border"></div>

                        <div class="flex items-center justify-between py-2">
                            <div>
                                <p class="text-sm text-aegis-text">Clear All Data</p>
                                <p class="text-xs text-aegis-text-dim">Delete all conversations, messages, and memories.</p>
                            </div>
                            <button
                                type="button"
                                wire:click="clearAllData"
                                wire:confirm="This will permanently delete ALL conversations, messages, and memories. This cannot be undone. Continue?"
                                class="px-3 py-1.5 rounded-lg text-xs font-medium text-red-300 border border-red-400/20 bg-red-500/5 hover:bg-red-500/15 transition-colors"
                            >
                                Clear All Data
                            </button>
                        </div>

                        <div class="border-t border-aegis-border"></div>

                        <div class="flex items-center justify-between py-2">
                            <div>
                                <p class="text-sm text-aegis-text">Export Data</p>
                                <p class="text-xs text-aegis-text-dim">Download all your data as a file.</p>
                            </div>
                            <button
                                type="button"
                                disabled
                                class="px-3 py-1.5 rounded-lg text-xs font-medium text-aegis-text-dim border border-aegis-border bg-aegis-800 opacity-50 cursor-not-allowed"
                            >
                                Coming soon
                            </button>
                        </div>
                    </div>
                </div>

                {{-- App Info --}}
                <div class="rounded-xl border border-aegis-border bg-aegis-850 p-5">
                    <div class="flex items-center justify-between">
                        <div>
                            <h4 class="text-sm font-semibold text-aegis-text">{{ config('aegis.name', 'Aegis') }}</h4>
                            <p class="text-xs text-aegis-text-dim mt-0.5">{{ config('aegis.tagline') }}</p>
                        </div>
                        <span class="text-xs font-mono text-aegis-text-dim">v{{ config('aegis.version', '0.1.0') }}</span>
                    </div>
                </div>
            </div>
        @endif

    </div>
</div>
