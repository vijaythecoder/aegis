<div class="max-w-4xl mx-auto px-6 py-8 space-y-6">
    <div class="flex items-center justify-between">
        <h2 class="text-lg font-display font-bold text-aegis-text">Security Dashboard</h2>
        <a href="{{ route('chat') }}" class="text-xs text-aegis-text-dim hover:text-aegis-text transition-colors">&larr; Back to Chat</a>
    </div>

    @if($flashMessage)
        <div class="px-4 py-2.5 rounded-lg text-sm {{ $flashType === 'error' ? 'bg-red-500/10 text-red-400 border border-red-500/20' : 'bg-emerald-500/10 text-emerald-400 border border-emerald-500/20' }}">
            {{ $flashMessage }}
        </div>
    @endif

    <div class="bg-aegis-850 border border-aegis-border rounded-xl p-5">
        <div class="flex items-center justify-between mb-4">
            <h3 class="text-sm font-semibold text-aegis-text">Audit Log Integrity</h3>
            <button wire:click="verifyIntegrity" wire:loading.attr="disabled" class="px-3 py-1.5 bg-aegis-accent text-aegis-900 text-xs font-semibold rounded-md hover:bg-aegis-accent/90 transition-colors">
                <span wire:loading.remove wire:target="verifyIntegrity">Verify Integrity</span>
                <span wire:loading wire:target="verifyIntegrity">Checking...</span>
            </button>
        </div>
        @if($integrityResult)
            <div class="flex items-center gap-3 text-sm">
                <span class="w-2 h-2 rounded-full {{ $integrityResult['valid'] ? 'bg-emerald-400' : 'bg-red-400' }}"></span>
                <span class="text-aegis-text">{{ $integrityResult['verified'] }}/{{ $integrityResult['total'] }} entries verified</span>
                @if(!$integrityResult['valid'])
                    <span class="text-red-400 text-xs">First failure at entry #{{ $integrityResult['first_failure'] }}</span>
                @endif
            </div>
        @else
            <p class="text-xs text-aegis-text-dim">Click "Verify Integrity" to check the HMAC chain.</p>
        @endif
    </div>

    <div class="bg-aegis-850 border border-aegis-border rounded-xl">
        <div class="px-5 py-3 border-b border-aegis-border flex items-center justify-between">
            <h3 class="text-sm font-semibold text-aegis-text">Capability Tokens</h3>
        </div>
        @if($this->capabilityTokens->isEmpty())
            <div class="px-5 py-6 text-center text-sm text-aegis-text-dim">No active capability tokens.</div>
        @else
            <div class="divide-y divide-aegis-border">
                @foreach($this->capabilityTokens as $token)
                    <div class="px-5 py-3 flex items-center justify-between gap-4">
                        <div class="min-w-0 flex-1">
                            <p class="text-sm font-medium text-aegis-text">{{ $token->capability }}</p>
                            <div class="flex items-center gap-3 mt-0.5">
                                <span class="text-xs text-aegis-text-dim">Scope: {{ $token->scope ?? 'any' }}</span>
                                <span class="text-xs text-aegis-text-dim">Issuer: {{ $token->issuer }}</span>
                                @if($token->expires_at)
                                    <span class="text-xs text-aegis-text-dim">Expires: {{ $token->expires_at->diffForHumans() }}</span>
                                @endif
                            </div>
                        </div>
                        <button wire:click="revokeToken({{ $token->id }})" wire:confirm="Revoke this capability token?" class="p-1.5 rounded-md text-aegis-text-dim hover:text-red-400 hover:bg-red-500/10 transition-colors" title="Revoke">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round">
                                <circle cx="12" cy="12" r="10"/>
                                <line x1="15" y1="9" x2="9" y2="15"/>
                                <line x1="9" y1="9" x2="15" y2="15"/>
                            </svg>
                        </button>
                    </div>
                @endforeach
            </div>
        @endif
    </div>

    <div class="bg-aegis-850 border border-aegis-border rounded-xl">
        <div class="px-5 py-3 border-b border-aegis-border flex items-center justify-between">
            <h3 class="text-sm font-semibold text-aegis-text">Audit Logs</h3>
            <input wire:model.live.debounce.300ms="filterAction" type="text" placeholder="Filter by action..." class="text-xs bg-aegis-surface border border-aegis-border rounded-md px-2.5 py-1 text-aegis-text placeholder:text-aegis-text-dim/50 focus:outline-none focus:ring-1 focus:ring-aegis-accent" />
        </div>
        @if($this->auditLogs->isEmpty())
            <div class="px-5 py-6 text-center text-sm text-aegis-text-dim">No audit logs found.</div>
        @else
            <div class="divide-y divide-aegis-border max-h-96 overflow-y-auto">
                @foreach($this->auditLogs as $log)
                    <div class="px-5 py-2.5 flex items-center gap-4 text-xs">
                        <span class="w-16 shrink-0 text-aegis-text-dim">{{ $log->created_at->format('H:i:s') }}</span>
                        <span class="w-28 shrink-0 font-medium text-aegis-text truncate">{{ $log->action }}</span>
                        <span class="w-24 shrink-0 text-aegis-text-dim truncate">{{ $log->tool_name }}</span>
                        <span class="flex-1 text-aegis-text-dim truncate">{{ $log->result?->value ?? '-' }}</span>
                        <span class="w-4 shrink-0 {{ $log->signature ? 'text-emerald-400' : 'text-aegis-text-dim/30' }}" title="{{ $log->signature ? 'Signed' : 'Unsigned' }}">
                            @if($log->signature)
                                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 11.08V12a10 10 0 11-5.93-9.14"/><polyline points="22 4 12 14.01 9 11.01"/></svg>
                            @else
                                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/></svg>
                            @endif
                        </span>
                    </div>
                @endforeach
            </div>
        @endif
    </div>
</div>
