<div
    class="flex flex-col h-full"
    x-data="{
        scrollToBottom() {
            $nextTick(() => {
                const el = document.getElementById('chat-messages');
                if (el) el.scrollTop = el.scrollHeight;
            });
        },
        focusInput() {
            $nextTick(() => {
                const ta = this.$refs.messageInput;
                if (ta) { ta.disabled = false; ta.focus(); }
            });
        },
        observeStream() {
            const target = document.getElementById('stream-target');
            if (!target) return;
            const observer = new MutationObserver(() => this.scrollToBottom());
            observer.observe(target, { childList: true, characterData: true, subtree: true });
        }
    }"
    x-init="scrollToBottom()"
    x-on:message-sent.window="scrollToBottom(); focusInput()"
    x-on:conversation-selected.window="setTimeout(() => scrollToBottom(), 100)"
>
    @if ($conversationId === null && $messages->isEmpty() && $pendingMessage === '')
        <div class="flex-1 flex items-center justify-center p-8">
            <div class="max-w-md text-center space-y-6">
                <div class="mx-auto w-16 h-16 rounded-2xl bg-gradient-to-br from-aegis-accent/20 to-aegis-accent-dim/10 border border-aegis-accent/20 flex items-center justify-center">
                    <svg class="w-8 h-8 text-aegis-accent" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M21 15a2 2 0 01-2 2H7l-4 4V5a2 2 0 012-2h14a2 2 0 012 2z"/>
                    </svg>
                </div>
                <div>
                    <h2 class="font-display font-bold text-xl tracking-tight text-aegis-text">Start a conversation</h2>
                    <p class="mt-2 text-sm text-aegis-text-dim leading-relaxed">Ask anything. Aegis can read files, run commands, and help you build.</p>
                </div>
            </div>
        </div>
    @else
        <div id="chat-messages" class="flex-1 overflow-y-auto" x-ref="messages">
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
                            observeStream();
                            const target = document.getElementById('stream-target');
                            if (target) {
                                new MutationObserver((mutations, obs) => {
                                    streaming = true;
                                    obs.disconnect();
                                }).observe(target, { childList: true, characterData: true, subtree: true });
                            }
                        })"
                    >
                        <div class="max-w-[85%] rounded-2xl rounded-bl-md px-4 py-3 bg-aegis-surface border border-aegis-border text-aegis-text text-sm leading-relaxed">
                            <div x-show="!streaming" class="flex items-center gap-1.5">
                                <span class="w-1.5 h-1.5 rounded-full bg-aegis-accent animate-bounce" style="animation-delay: 0ms"></span>
                                <span class="w-1.5 h-1.5 rounded-full bg-aegis-accent animate-bounce" style="animation-delay: 150ms"></span>
                                <span class="w-1.5 h-1.5 rounded-full bg-aegis-accent animate-bounce" style="animation-delay: 300ms"></span>
                            </div>
                            <div id="stream-target" class="markdown-body text-sm max-w-none" wire:stream="streamedResponse"></div>
                        </div>
                    </div>
                @endif
            </div>
        </div>
    @endif

    <div class="shrink-0 border-t border-aegis-border bg-aegis-900/80 backdrop-blur-sm p-4">
        <div class="max-w-3xl mx-auto">
            <form wire:submit="sendMessage">
                <div class="relative flex items-end gap-3 rounded-xl border border-aegis-border bg-aegis-surface p-2 focus-within:border-aegis-accent/40 transition-colors">
                    <textarea
                        x-ref="messageInput"
                        wire:model="message"
                        x-on:keydown.enter.prevent="if (!$event.shiftKey) $wire.sendMessage()"
                        placeholder="Message Aegis..."
                        rows="1"
                        @if ($isThinking) disabled @endif
                        class="flex-1 resize-none bg-transparent text-sm text-aegis-text placeholder-aegis-text-dim focus:outline-none px-2 py-1.5 max-h-40 overflow-y-auto disabled:opacity-50"
                        x-data="{ resize() { $el.style.height = 'auto'; $el.style.height = Math.min($el.scrollHeight, 160) + 'px'; } }"
                        x-on:input="resize()"
                    ></textarea>
                    <button
                        type="submit"
                        @if ($isThinking) disabled @endif
                        class="shrink-0 p-2 rounded-lg bg-aegis-accent text-aegis-900 hover:bg-aegis-glow disabled:opacity-40 disabled:cursor-not-allowed transition-colors"
                    >
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                            <line x1="22" y1="2" x2="11" y2="13"/>
                            <polygon points="22 2 15 22 11 13 2 9 22 2"/>
                        </svg>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
