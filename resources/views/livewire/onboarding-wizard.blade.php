<div
    x-data="{ entered: false }"
    x-init="$nextTick(() => entered = true)"
    class="w-full max-w-xl mx-auto"
>
    {{-- Step Indicator --}}
    <div class="flex items-center justify-center gap-3 mb-10">
        @for ($i = 1; $i <= $totalSteps; $i++)
            <button
                wire:click="goToStep({{ $i }})"
                class="group relative flex items-center gap-2 transition-all duration-300"
            >
                <div @class([
                    'w-9 h-9 rounded-full flex items-center justify-center text-xs font-semibold transition-all duration-300 border',
                    'bg-aegis-accent border-aegis-accent text-aegis-900 shadow-lg shadow-aegis-accent/25' => $currentStep === $i,
                    'bg-aegis-accent/15 border-aegis-accent/40 text-aegis-accent' => $currentStep > $i,
                    'bg-aegis-800 border-aegis-border text-aegis-text-dim' => $currentStep < $i,
                ])>
                    @if ($currentStep > $i)
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                    @else
                        {{ $i }}
                    @endif
                </div>
                @if ($i < $totalSteps)
                    <div @class([
                        'w-8 h-px transition-all duration-300',
                        'bg-aegis-accent/40' => $currentStep > $i,
                        'bg-aegis-border' => $currentStep <= $i,
                    ])></div>
                @endif
            </button>
        @endfor
    </div>

    {{-- Step Content --}}
    <div class="rounded-2xl border border-aegis-border bg-aegis-850 p-8 shadow-2xl shadow-black/30 relative overflow-hidden">

        {{-- Step 1: Welcome --}}
        @if ($currentStep === 1)
            <div
                x-show="entered"
                x-transition:enter="transition-all duration-400 ease-out"
                x-transition:enter-start="opacity-0 translate-y-3"
                x-transition:enter-end="opacity-100 translate-y-0"
                class="text-center space-y-6 relative"
            >
                {{-- Shield Icon --}}
                <div class="mx-auto w-20 h-20 rounded-2xl bg-gradient-to-br from-aegis-accent/20 to-aegis-accent-dim/10 border border-aegis-accent/20 flex items-center justify-center shadow-2xl shadow-aegis-accent/10">
                    <svg class="w-10 h-10 text-aegis-accent" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                    </svg>
                </div>

                <div>
                    <h2 class="font-display font-bold text-2xl tracking-tight text-aegis-text">
                        Welcome to <span class="text-aegis-accent">{{ config('aegis.name', 'Aegis') }}</span>
                    </h2>
                    <p class="mt-1 text-sm text-aegis-accent-dim font-medium tracking-wide">
                        {{ config('aegis.tagline', 'AI under your Aegis') }}
                    </p>
                </div>

                <p class="text-sm text-aegis-text-dim leading-relaxed max-w-md mx-auto">
                    Aegis is your local AI assistant with full tool access — file editing, shell commands, and code execution — all running privately on your machine with security controls you define.
                </p>

                <div class="pt-2">
                    <button
                        wire:click="nextStep"
                        class="inline-flex items-center gap-2 px-6 py-2.5 rounded-lg bg-aegis-accent text-aegis-900 font-semibold text-sm transition-all duration-200 hover:shadow-lg hover:shadow-aegis-accent/20 hover:scale-[1.02] active:scale-[0.98]"
                    >
                        Get Started
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                    </button>
                </div>
            </div>
        @endif

        {{-- Step 2: Provider Setup --}}
        @if ($currentStep === 2)
            <div
                x-show="entered"
                x-transition:enter="transition-all duration-400 ease-out"
                x-transition:enter-start="opacity-0 translate-y-3"
                x-transition:enter-end="opacity-100 translate-y-0"
                class="space-y-6 relative"
            >
                <div class="text-center">
                    <div class="mx-auto w-12 h-12 rounded-xl bg-aegis-800 border border-aegis-border flex items-center justify-center mb-4">
                        <svg class="w-6 h-6 text-aegis-accent" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M4 15s1-1 4-1 5 2 8 2 4-1 4-1V3s-1 1-4 1-5-2-8-2-4 1-4 1z"/>
                            <line x1="4" y1="22" x2="4" y2="15"/>
                        </svg>
                    </div>
                    <h3 class="font-display font-bold text-xl text-aegis-text">Choose Your AI Provider</h3>
                    <p class="mt-1 text-sm text-aegis-text-dim">Select a default provider and enter your API key.</p>
                </div>

                <div class="space-y-4">
                    {{-- Provider Select --}}
                    <div>
                        <label for="provider" class="block text-xs font-medium text-aegis-text-dim uppercase tracking-wider mb-2">Provider</label>
                        <select
                            id="provider"
                            wire:model.live="selectedProvider"
                            class="w-full rounded-lg border border-aegis-border bg-aegis-900 px-4 py-2.5 text-sm text-aegis-text focus:border-aegis-accent/50 focus:outline-none focus:ring-1 focus:ring-aegis-accent/30 transition-colors"
                        >
                            @foreach ($providers as $key => $provider)
                                <option value="{{ $key }}">{{ $provider['name'] }}</option>
                            @endforeach
                        </select>
                    </div>

                    {{-- API Key Input --}}
                    @if ($requiresKey)
                        <div>
                            <label for="apiKey" class="block text-xs font-medium text-aegis-text-dim uppercase tracking-wider mb-2">API Key</label>
                            <input
                                id="apiKey"
                                type="password"
                                wire:model="apiKey"
                                placeholder="Enter your API key..."
                                class="w-full rounded-lg border border-aegis-border bg-aegis-900 px-4 py-2.5 text-sm text-aegis-text placeholder:text-aegis-text-dim/50 font-mono focus:border-aegis-accent/50 focus:outline-none focus:ring-1 focus:ring-aegis-accent/30 transition-colors"
                            />
                            @error('apiKey')
                                <p class="mt-1.5 text-xs text-red-400">{{ $message }}</p>
                            @enderror
                        </div>
                    @else
                        <div class="rounded-lg border border-aegis-accent/20 bg-aegis-accent/5 p-3">
                            <p class="text-xs text-aegis-accent">
                                <span class="font-semibold">Ollama</span> runs locally — no API key required. Make sure Ollama is running on your machine.
                            </p>
                        </div>
                    @endif

                    {{-- Save / Test --}}
                    <div class="flex items-center gap-3">
                        <button
                            wire:click="saveProvider"
                            class="inline-flex items-center gap-2 px-4 py-2 rounded-lg border border-aegis-accent/40 bg-aegis-accent/10 text-sm font-medium text-aegis-accent transition hover:bg-aegis-accent/20"
                        >
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                            Test Connection
                        </button>
                        @if ($providerSaved)
                            <span class="text-xs text-aegis-accent font-medium flex items-center gap-1">
                                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                                Saved
                            </span>
                        @endif
                    </div>
                </div>

                {{-- Navigation --}}
                <div class="flex items-center justify-between pt-2">
                    <button
                        wire:click="previousStep"
                        class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-sm text-aegis-text-dim hover:text-aegis-text hover:bg-aegis-surface-hover transition-colors"
                    >
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                        Back
                    </button>
                    <button
                        wire:click="nextStep"
                        class="inline-flex items-center gap-2 px-5 py-2 rounded-lg bg-aegis-accent text-aegis-900 font-semibold text-sm transition-all hover:shadow-lg hover:shadow-aegis-accent/20"
                    >
                        Next
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                    </button>
                </div>
            </div>
        @endif

        {{-- Step 3: Security Preview --}}
        @if ($currentStep === 3)
            <div
                x-show="entered"
                x-transition:enter="transition-all duration-400 ease-out"
                x-transition:enter-start="opacity-0 translate-y-3"
                x-transition:enter-end="opacity-100 translate-y-0"
                class="space-y-6 relative"
            >
                <div class="text-center">
                    <div class="mx-auto w-12 h-12 rounded-xl bg-aegis-800 border border-aegis-border flex items-center justify-center mb-4">
                        <svg class="w-6 h-6 text-aegis-accent" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
                            <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
                        </svg>
                    </div>
                    <h3 class="font-display font-bold text-xl text-aegis-text">Security Defaults</h3>
                    <p class="mt-1 text-sm text-aegis-text-dim">How Aegis keeps your system safe by default.</p>
                </div>

                <div class="space-y-3">
                    {{-- Read operations --}}
                    <div class="flex items-start gap-4 p-4 rounded-xl bg-aegis-900/60 border border-aegis-border">
                        <div class="w-10 h-10 rounded-lg bg-green-500/10 border border-green-500/20 flex items-center justify-center shrink-0">
                            <svg class="w-5 h-5 text-green-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M1 12s4-8 11-8 11 8 11 8-4 8-11 8-11-8-11-8z"/><circle cx="12" cy="12" r="3"/></svg>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-aegis-text">Read operations auto-allowed</p>
                            <p class="text-xs text-aegis-text-dim mt-0.5">File reads and directory listings run without prompts, keeping your workflow smooth.</p>
                        </div>
                    </div>

                    {{-- Write/Shell operations --}}
                    <div class="flex items-start gap-4 p-4 rounded-xl bg-aegis-900/60 border border-aegis-border">
                        <div class="w-10 h-10 rounded-lg bg-amber-500/10 border border-amber-500/20 flex items-center justify-center shrink-0">
                            <svg class="w-5 h-5 text-amber-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M10.29 3.86L1.82 18a2 2 0 001.71 3h16.94a2 2 0 001.71-3L13.71 3.86a2 2 0 00-3.42 0z"/><line x1="12" y1="9" x2="12" y2="13"/><line x1="12" y1="17" x2="12.01" y2="17"/></svg>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-aegis-text">Write & shell operations need approval</p>
                            <p class="text-xs text-aegis-text-dim mt-0.5">Destructive actions like file writes and shell commands always ask for your permission first.</p>
                        </div>
                    </div>

                    {{-- Blocked commands --}}
                    <div class="flex items-start gap-4 p-4 rounded-xl bg-aegis-900/60 border border-aegis-border">
                        <div class="w-10 h-10 rounded-lg bg-red-500/10 border border-red-500/20 flex items-center justify-center shrink-0">
                            <svg class="w-5 h-5 text-red-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="4.93" y1="4.93" x2="19.07" y2="19.07"/></svg>
                        </div>
                        <div>
                            <p class="text-sm font-medium text-aegis-text">Dangerous commands blocked</p>
                            <p class="text-xs text-aegis-text-dim mt-0.5">Commands like <code class="px-1 py-0.5 rounded bg-aegis-800 text-aegis-text font-mono text-[11px]">rm -rf</code>, <code class="px-1 py-0.5 rounded bg-aegis-800 text-aegis-text font-mono text-[11px]">mkfs</code>, and others are permanently blocked.</p>
                        </div>
                    </div>
                </div>

                <p class="text-xs text-aegis-text-dim text-center">
                    You can customize these in <span class="text-aegis-accent">Settings</span> anytime.
                </p>

                {{-- Navigation --}}
                <div class="flex items-center justify-between pt-2">
                    <button
                        wire:click="previousStep"
                        class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-sm text-aegis-text-dim hover:text-aegis-text hover:bg-aegis-surface-hover transition-colors"
                    >
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                        Back
                    </button>
                    <button
                        wire:click="nextStep"
                        class="inline-flex items-center gap-2 px-5 py-2 rounded-lg bg-aegis-accent text-aegis-900 font-semibold text-sm transition-all hover:shadow-lg hover:shadow-aegis-accent/20"
                    >
                        Next
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                    </button>
                </div>
            </div>
        @endif

        {{-- Step 4: Ready --}}
        @if ($currentStep === 4)
            <div
                x-data="{ checked: false }"
                x-init="setTimeout(() => checked = true, 300)"
                x-show="true"
                x-transition:enter="transition-all duration-400 ease-out"
                x-transition:enter-start="opacity-0 translate-y-3"
                x-transition:enter-end="opacity-100 translate-y-0"
                class="text-center space-y-6 relative"
            >
                {{-- Animated Checkmark --}}
                <div class="mx-auto w-20 h-20 rounded-full bg-gradient-to-br from-aegis-accent/20 to-aegis-accent-dim/10 border border-aegis-accent/30 flex items-center justify-center shadow-2xl shadow-aegis-accent/15">
                    <svg
                        x-show="checked"
                        x-transition:enter="transition-all duration-500 ease-out"
                        x-transition:enter-start="opacity-0 scale-50"
                        x-transition:enter-end="opacity-100 scale-100"
                        class="w-10 h-10 text-aegis-accent"
                        viewBox="0 0 24 24"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    >
                        <polyline points="20 6 9 17 4 12"/>
                    </svg>
                </div>

                <div>
                    <h3 class="font-display font-bold text-2xl text-aegis-text">You're all set!</h3>
                    <p class="mt-2 text-sm text-aegis-text-dim leading-relaxed max-w-sm mx-auto">
                        Aegis is ready to go. Start a conversation and let your AI assistant help you build, explore, and create.
                    </p>
                </div>

                <div class="pt-2 space-y-3">
                    <button
                        wire:click="complete"
                        class="inline-flex items-center gap-2 px-6 py-2.5 rounded-lg bg-aegis-accent text-aegis-900 font-semibold text-sm transition-all duration-200 hover:shadow-lg hover:shadow-aegis-accent/20 hover:scale-[1.02] active:scale-[0.98]"
                    >
                        Start Using Aegis
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="5" y1="12" x2="19" y2="12"/><polyline points="12 5 19 12 12 19"/></svg>
                    </button>
                </div>

                {{-- Navigation --}}
                <div class="flex items-center justify-center pt-1">
                    <button
                        wire:click="previousStep"
                        class="inline-flex items-center gap-1.5 px-4 py-2 rounded-lg text-sm text-aegis-text-dim hover:text-aegis-text hover:bg-aegis-surface-hover transition-colors"
                    >
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="19" y1="12" x2="5" y2="12"/><polyline points="12 19 5 12 12 5"/></svg>
                        Back
                    </button>
                </div>
            </div>
        @endif
    </div>

    {{-- Skip Link --}}
    <div class="text-center mt-6">
        <button
            wire:click="skip"
            class="text-xs text-aegis-text-dim hover:text-aegis-text transition-colors underline underline-offset-2 decoration-aegis-text-dim/30 hover:decoration-aegis-text/50"
        >
            Skip setup — I'll configure later
        </button>
    </div>
</div>
