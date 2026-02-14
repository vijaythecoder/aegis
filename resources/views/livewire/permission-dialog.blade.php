<div>
    @if ($isVisible)
        <div
            wire:poll.1s="checkTimeout"
            x-data="{ now: Math.floor(Date.now() / 1000) }"
            x-init="setInterval(() => now = Math.floor(Date.now() / 1000), 1000)"
        >
            <div class="fixed inset-0 z-50 flex items-center justify-center bg-black/70 p-4 backdrop-blur-sm">
                <div class="w-full max-w-2xl rounded-2xl border border-aegis-border bg-aegis-850 p-6 shadow-2xl shadow-black/40">
                    <div class="mb-6 flex items-start justify-between gap-4">
                        <div>
                            <p class="text-xs uppercase tracking-[0.2em] text-aegis-text-dim">Permission Required</p>
                            <h3 class="mt-2 text-xl font-display font-bold text-aegis-text">{{ $toolName }}</h3>
                            <p class="mt-2 text-sm text-aegis-text-dim">
                                This tool requires <span class="font-medium text-aegis-accent">{{ $permission }}</span> permission.
                            </p>
                        </div>
                        <div class="rounded-lg border border-amber-400/40 bg-amber-500/10 px-3 py-2 text-right">
                            <p class="text-[11px] uppercase tracking-[0.12em] text-amber-300/80">Auto deny in</p>
                            <p class="font-mono text-sm text-amber-200" x-text="Math.max(0, {{ $expiresAt }} - now) + 's'"></p>
                        </div>
                    </div>

                    <div class="mb-6 rounded-xl border border-aegis-border bg-aegis-900/70 p-4">
                        <p class="mb-2 text-xs uppercase tracking-[0.12em] text-aegis-text-dim">Parameters</p>
                        <pre class="max-h-52 overflow-auto whitespace-pre-wrap break-words text-xs text-aegis-text">{{ json_encode($parameters, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) }}</pre>
                    </div>

                    <div class="flex flex-col gap-3 sm:flex-row sm:justify-end">
                        <button
                            type="button"
                            wire:click="deny"
                            class="rounded-lg border border-red-400/40 bg-red-500/10 px-4 py-2 text-sm font-medium text-red-200 transition hover:bg-red-500/20"
                        >
                            Deny
                        </button>
                        <button
                            type="button"
                            wire:click="allowOnce"
                            class="rounded-lg border border-aegis-border bg-aegis-surface-hover px-4 py-2 text-sm font-medium text-aegis-text transition hover:border-aegis-accent/50"
                        >
                            Allow Once
                        </button>
                        <button
                            type="button"
                            wire:click="alwaysAllow"
                            class="rounded-lg border border-aegis-accent/40 bg-aegis-accent/15 px-4 py-2 text-sm font-semibold text-aegis-accent transition hover:bg-aegis-accent/25"
                        >
                            Always Allow
                        </button>
                    </div>
                </div>
            </div>
        </div>
    @endif
</div>
