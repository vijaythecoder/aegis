<div class="flex items-center gap-3 text-xs">
    <div class="flex items-center gap-1.5">
        @if ($state === 'thinking')
            <span class="relative flex h-2 w-2">
                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-amber-400 opacity-75"></span>
                <span class="relative inline-flex h-2 w-2 rounded-full bg-amber-400"></span>
            </span>
            <span class="text-amber-300 font-medium">Thinking</span>
        @elseif ($state === 'executing-tool')
            <span class="relative flex h-2 w-2">
                <span class="absolute inline-flex h-full w-full animate-ping rounded-full bg-aegis-accent opacity-75"></span>
                <span class="relative inline-flex h-2 w-2 rounded-full bg-aegis-accent"></span>
            </span>
            <span class="text-aegis-accent font-medium">Executing</span>
        @else
            <span class="h-2 w-2 rounded-full bg-aegis-600"></span>
            <span class="text-aegis-text-dim">Idle</span>
        @endif
    </div>

    <span class="text-aegis-border">|</span>

    <span class="text-aegis-text-dim">{{ $provider }}</span>
    <span class="text-aegis-text-dim">/</span>
    <span class="text-aegis-text">{{ $model }}</span>

    @if ($conversationId)
        <span class="text-aegis-border">|</span>
        <span class="text-aegis-text-dim">{{ number_format($tokenCount) }} tokens</span>
        <span class="text-aegis-border">|</span>
        <span class="text-aegis-text-dim">Context: {{ number_format($tokenCount) }} / {{ number_format($contextWindow) }} tokens</span>
        <div class="h-1.5 w-24 overflow-hidden rounded-full bg-aegis-800/70">
            <div
                class="h-full rounded-full {{ $usageWidthClass }} {{ $contextUsagePercent >= 90 ? 'bg-red-400' : ($contextUsagePercent >= 75 ? 'bg-amber-400' : 'bg-aegis-accent') }}"
            ></div>
        </div>
    @endif
</div>
