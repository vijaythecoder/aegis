<div
    class="flex flex-col h-full"
    x-data="{
        agentPhase: 'idle',
        agentDetail: '',
        scrollToBottom() {
            $nextTick(() => {
                const el = document.getElementById('chat-messages');
                if (el) el.scrollTop = el.scrollHeight;
            });
        },
        focusInput() {
            $nextTick(() => {
                const ta = this.$refs.messageInput;
                if (ta) ta.focus();
            });
        },
        stopGenerating() {
            Livewire.navigate(window.location.href);
        },
        phaseIcon(phase) {
            const icons = {
                thinking: 'M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2z',
                planning: 'M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2',
                executing: 'M13 10V3L4 14h7v7l9-11h-7z',
                reflecting: 'M15 12a3 3 0 11-6 0 3 3 0 016 0z M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z',
                retrying: 'M4 4v5h.582m15.356 2A8.001 8.001 0 004.582 9m0 0H9m11 11v-5h-.581m0 0a8.003 8.003 0 01-15.357-2m15.357 2H15',
            };
            return icons[phase] || icons.thinking;
        },
        phaseLabel(phase) {
            const labels = { planning: 'Planning', executing: 'Executing', reflecting: 'Reviewing', retrying: 'Improving' };
            return labels[phase] || 'Thinking';
        }
    }"
    x-init="scrollToBottom()"
    x-on:message-sent.window="scrollToBottom(); focusInput()"
    x-on:conversation-selected.window="setTimeout(() => { scrollToBottom(); focusInput(); }, 100)"
    x-on:agent-status-changed.window="agentPhase = $event.detail.state || 'idle'; agentDetail = $event.detail.detail || ''"
>
    @if ($conversationId === null && $messages->isEmpty() && $pendingMessage === '')
        <div class="flex-1 flex items-center justify-center p-8">
            <div class="max-w-md text-center space-y-6">
                <div class="mx-auto w-16 h-16 rounded-2xl bg-gradient-to-br from-aegis-accent/20 to-aegis-accent-dim/10 border border-aegis-accent/20 flex items-center justify-center">
                    @if ($agentAvatar)
                        <span class="text-3xl">{{ $agentAvatar }}</span>
                    @else
                        <svg class="w-8 h-8 text-aegis-accent" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>
                        </svg>
                    @endif
                </div>
                <div>
                    <h2 class="font-display font-bold text-xl tracking-tight text-aegis-text">
                        @if ($agentName)
                            Chat with {{ $agentName }}
                        @else
                            Start a conversation
                        @endif
                    </h2>
                    <p class="mt-2 text-sm text-aegis-text-dim leading-relaxed">
                        @if ($agentName)
                            {{ $agentName }} is ready to help you.
                        @else
                            Ask anything. Aegis can read files, run commands, and help you build.
                        @endif
                    </p>
                </div>
            </div>
        </div>
    @else
        <div id="chat-messages" class="flex-1 overflow-y-auto" x-ref="messages">
            @if ($agentName)
                <div class="max-w-3xl mx-auto px-4 pt-4 pb-0">
                    <div class="flex items-center gap-2 px-3 py-2 rounded-lg bg-aegis-accent/5 border border-aegis-accent/10">
                        <span class="text-lg leading-none">{{ $agentAvatar ?? '🤖' }}</span>
                        <span class="text-sm font-medium text-aegis-accent">{{ $agentName }}</span>
                    </div>
                </div>
            @endif
            <div class="max-w-3xl mx-auto px-4 py-6 space-y-6">
                @foreach ($messages as $msg)
                    @if ($msg->role === \App\Enums\MessageRole::User)
                        <div class="flex justify-end">
                            <div class="max-w-[85%] rounded-2xl rounded-br-md px-4 py-3 bg-aegis-accent/15 border border-aegis-accent/20 text-aegis-text text-sm leading-relaxed">
                                {{ $msg->content }}
                            </div>
                        </div>
                    @elseif ($msg->role === \App\Enums\MessageRole::Assistant)
                        <div class="flex justify-start">
                            <div class="max-w-[85%] rounded-2xl rounded-bl-md px-4 py-3 bg-aegis-surface border border-aegis-border text-aegis-text text-sm leading-relaxed">
                                <div class="markdown-body text-sm max-w-none">
                                    {!! \Illuminate\Support\Str::markdown($msg->content, ['html_input' => 'strip', 'allow_unsafe_links' => false]) !!}
                                </div>
                            </div>
                        </div>
                    @elseif ($msg->role === \App\Enums\MessageRole::Tool)
                        <div class="flex justify-start" x-data="{ expanded: false }">
                            <div class="max-w-[85%] rounded-xl border border-aegis-border bg-aegis-900/50 overflow-hidden">
                                <button
                                    @click="expanded = !expanded"
                                    class="w-full flex items-center gap-2 px-3 py-2 text-xs hover:bg-aegis-surface-hover transition-colors"
                                >
                                    <svg class="w-3 h-3 text-aegis-accent shrink-0" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <rect x="2" y="3" width="20" height="14" rx="2" ry="2"/>
                                        <line x1="8" y1="21" x2="16" y2="21"/>
                                        <line x1="12" y1="17" x2="12" y2="21"/>
                                    </svg>
                                    <span class="font-mono font-medium text-aegis-accent">{{ $msg->tool_name }}</span>
                                    <svg
                                        class="w-3 h-3 text-aegis-text-dim ml-auto transition-transform"
                                        :class="{ 'rotate-180': expanded }"
                                        viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"
                                    >
                                        <polyline points="6 9 12 15 18 9"/>
                                    </svg>
                                </button>
                                <div
                                    x-show="expanded"
                                    x-transition:enter="transition-all duration-150 ease-out"
                                    x-transition:enter-start="opacity-0 max-h-0"
                                    x-transition:enter-end="opacity-100 max-h-96"
                                    x-transition:leave="transition-all duration-100 ease-in"
                                    x-transition:leave-start="opacity-100 max-h-96"
                                    x-transition:leave-end="opacity-0 max-h-0"
                                    class="border-t border-aegis-border"
                                >
                                    <pre class="p-3 text-xs font-mono text-aegis-text-dim overflow-x-auto max-h-60 overflow-y-auto leading-relaxed">{{ $msg->content }}</pre>
                                </div>
                            </div>
                        </div>
                    @endif
                @endforeach

                @if ($pendingMessage !== '')
                    <div class="flex justify-end">
                        <div class="max-w-[85%] rounded-2xl rounded-br-md px-4 py-3 bg-aegis-accent/15 border border-aegis-accent/20 text-aegis-text text-sm leading-relaxed">
                            {{ $pendingMessage }}
                        </div>
                    </div>
                @endif

                @if ($isThinking)
                    <div
                        class="flex justify-start"
                        x-data="{ streaming: false }"
                        x-init="$nextTick(() => {
                            const raw = document.getElementById('stream-raw');
                            const rendered = document.getElementById('stream-rendered');
                            if (!raw || !rendered) return;
                            const observer = new MutationObserver(() => {
                                streaming = true;
                                const text = raw.textContent || '';
                                rendered.innerHTML = typeof window.markedParse === 'function' ? window.markedParse(text) : text;
                                scrollToBottom();
                            });
                            observer.observe(raw, { childList: true, characterData: true, subtree: true });
                        })"
                    >
                        <div class="max-w-[85%] rounded-2xl rounded-bl-md px-4 py-3 bg-aegis-surface border border-aegis-border text-aegis-text text-sm leading-relaxed">
                            <div x-show="!streaming" x-cloak>
                                <div x-show="agentPhase !== 'idle' && agentPhase !== 'thinking'" class="flex items-center gap-2">
                                    <svg class="w-4 h-4 text-aegis-accent animate-spin" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                        <path :d="phaseIcon(agentPhase)"/>
                                    </svg>
                                    <span class="text-xs font-medium text-aegis-accent" x-text="phaseLabel(agentPhase)"></span>
                                    <span class="text-xs text-aegis-text-dim" x-show="agentDetail" x-text="agentDetail"></span>
                                </div>
                                <div x-show="agentPhase === 'idle' || agentPhase === 'thinking'" class="flex items-center gap-1.5">
                                    <span class="w-1.5 h-1.5 rounded-full bg-aegis-accent animate-bounce" style="animation-delay: 0ms"></span>
                                    <span class="w-1.5 h-1.5 rounded-full bg-aegis-accent animate-bounce" style="animation-delay: 150ms"></span>
                                    <span class="w-1.5 h-1.5 rounded-full bg-aegis-accent animate-bounce" style="animation-delay: 300ms"></span>
                                </div>
                            </div>
                            <div id="stream-raw" class="hidden" wire:stream="streamedResponse"></div>
                            <div id="stream-rendered" class="markdown-body text-sm max-w-none"></div>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @endif

    <div class="shrink-0 border-t border-aegis-border bg-aegis-900/80 backdrop-blur-sm p-4">
        <div class="max-w-3xl mx-auto">
            <div class="flex items-center gap-2 mb-2 text-xs">
                <span class="text-aegis-text-dim shrink-0">Model:</span>
                <select
                    wire:model.live="selectedProvider"
                    class="bg-aegis-surface border border-aegis-border rounded-md px-2 py-1 text-xs text-aegis-text focus:border-aegis-accent/40 focus:outline-none disabled:opacity-50"
                    @if($isThinking) disabled @endif
                >
                    @foreach ($availableProviders as $providerId => $providerName)
                        <option value="{{ $providerId }}">{{ $providerName }}</option>
                    @endforeach
                </select>
                <span class="text-aegis-border">/</span>
                <select
                    wire:model.live="selectedModel"
                    class="bg-aegis-surface border border-aegis-border rounded-md px-2 py-1 text-xs text-aegis-text focus:border-aegis-accent/40 focus:outline-none flex-1 min-w-0 truncate disabled:opacity-50"
                    @if($isThinking) disabled @endif
                >
                    @foreach ($availableModels as $modelId)
                        <option value="{{ $modelId }}">{{ $modelId }}</option>
                    @endforeach
                </select>
            </div>
            <form wire:submit="sendMessage">
                <div class="relative flex items-end gap-3 rounded-xl border border-aegis-border bg-aegis-surface p-2 focus-within:border-aegis-accent/40 transition-colors">
                    <textarea
                        x-ref="messageInput"
                        wire:model="message"
                        x-on:keydown.enter.prevent="if (!$event.shiftKey && !$wire.isThinking) { $wire.sendMessage(); $nextTick(() => { $el.style.height = 'auto'; }) }"
                        placeholder="Message {{ $agentName ?? 'Aegis' }}..."
                        rows="1"
                        class="flex-1 resize-none bg-transparent text-sm text-aegis-text placeholder-aegis-text-dim focus:outline-none px-2 py-1.5 max-h-40 overflow-y-auto"
                        x-data="{ resize() { $el.style.height = 'auto'; $el.style.height = Math.min($el.scrollHeight, 160) + 'px'; } }"
                        x-on:input="resize()"
                        x-effect="if (!$wire.isThinking) $nextTick(() => $el.focus())"
                    ></textarea>
                    @if ($isThinking)
                        <button
                            type="button"
                            x-on:click="stopGenerating()"
                            class="shrink-0 p-2 rounded-lg bg-red-500/20 text-red-300 hover:bg-red-500/30 transition-colors"
                            title="Stop generating"
                        >
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor">
                                <rect x="6" y="6" width="12" height="12" rx="2"/>
                            </svg>
                        </button>
                    @else
                        <button
                            type="submit"
                            class="shrink-0 p-2 rounded-lg bg-aegis-accent text-aegis-900 hover:bg-aegis-glow transition-colors"
                        >
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                                <line x1="22" y1="2" x2="11" y2="13"/>
                                <polygon points="22 2 15 22 11 13 2 9 22 2"/>
                            </svg>
                        </button>
                    @endif
                </div>
            </form>
        </div>
    </div>
</div>
