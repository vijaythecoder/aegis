<div class="space-y-6">

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

    <div class="flex items-center justify-between">
        <div>
            <h3 class="text-lg font-display font-bold text-aegis-text">Agent Management</h3>
            <p class="text-sm text-aegis-text-dim mt-1">Create and manage your AI agents. {{ $agentCount }}/10 agents.</p>
        </div>
        @if (!$showForm)
            <button
                wire:click="newAgent"
                @if($atLimit) disabled @endif
                @class([
                    'flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium transition-all duration-150',
                    'bg-aegis-accent text-aegis-900 hover:bg-aegis-glow' => !$atLimit,
                    'bg-aegis-600 text-aegis-text-dim cursor-not-allowed' => $atLimit,
                ])
            >
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="12" y1="5" x2="12" y2="19"/>
                    <line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                New Agent
            </button>
        @endif
    </div>

    @if ($showForm)
        <div class="rounded-xl border border-aegis-border bg-aegis-850 p-6 space-y-5">
            <h4 class="text-sm font-semibold text-aegis-text">{{ $editingAgentId ? 'Edit Agent' : 'Create Agent' }}</h4>

            <div class="grid grid-cols-[1fr_auto] gap-4">
                <div>
                    <label class="block text-xs font-medium text-aegis-text-dim mb-1.5">Name</label>
                    <input wire:model="name" type="text" maxlength="50" placeholder="e.g. FitCoach" class="w-full bg-aegis-surface border border-aegis-border rounded-lg px-3 py-2 text-sm text-aegis-text placeholder-aegis-text-dim focus:outline-none focus:border-aegis-accent/40 transition-colors" />
                    @error('name') <span class="text-xs text-red-400 mt-1">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-aegis-text-dim mb-1.5">Avatar</label>
                    <input wire:model="avatar" type="text" maxlength="10" class="w-20 bg-aegis-surface border border-aegis-border rounded-lg px-3 py-2 text-center text-lg focus:outline-none focus:border-aegis-accent/40 transition-colors" />
                </div>
            </div>

            <div>
                <label class="block text-xs font-medium text-aegis-text-dim mb-1.5">Persona</label>
                <textarea wire:model.blur="persona" rows="4" maxlength="5000" placeholder="Describe the agent's personality and expertise..." class="w-full bg-aegis-surface border border-aegis-border rounded-lg px-3 py-2 text-sm text-aegis-text placeholder-aegis-text-dim focus:outline-none focus:border-aegis-accent/40 transition-colors resize-none"></textarea>
                @error('persona') <span class="text-xs text-red-400 mt-1">{{ $message }}</span> @enderror
            </div>

            @if ($suggestedSkills !== [] && !$editingAgentId)
                <div class="flex flex-wrap items-center gap-2">
                    <span class="text-xs text-aegis-text-dim">Suggested:</span>
                    @foreach ($suggestedSkills as $suggestion)
                        <button
                            wire:click="applySuggestedSkill({{ $suggestion['id'] }})"
                            type="button"
                            class="inline-flex items-center gap-1 px-2 py-1 rounded-md border border-aegis-accent/30 bg-aegis-accent/5 text-xs text-aegis-accent hover:bg-aegis-accent/15 transition-colors"
                        >
                            <svg class="w-3 h-3" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
                            {{ $suggestion['name'] }}
                        </button>
                    @endforeach
                </div>
            @endif

            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-xs font-medium text-aegis-text-dim mb-1.5">Provider <span class="text-aegis-text-dim/50">(optional)</span></label>
                    <input wire:model="provider" type="text" placeholder="Use default" class="w-full bg-aegis-surface border border-aegis-border rounded-lg px-3 py-2 text-sm text-aegis-text placeholder-aegis-text-dim focus:outline-none focus:border-aegis-accent/40 transition-colors" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-aegis-text-dim mb-1.5">Model <span class="text-aegis-text-dim/50">(optional)</span></label>
                    <input wire:model="model" type="text" placeholder="Use default" class="w-full bg-aegis-surface border border-aegis-border rounded-lg px-3 py-2 text-sm text-aegis-text placeholder-aegis-text-dim focus:outline-none focus:border-aegis-accent/40 transition-colors" />
                </div>
            </div>

            @if ($availableSkills->isNotEmpty())
                <div>
                    <label class="block text-xs font-medium text-aegis-text-dim mb-2">Skills</label>
                    <div class="flex flex-wrap gap-2">
                        @foreach ($availableSkills as $skill)
                            <label class="flex items-center gap-1.5 px-2.5 py-1.5 rounded-lg border cursor-pointer transition-all duration-150 text-xs
                                {{ in_array($skill->id, $selectedSkills) ? 'border-aegis-accent/40 bg-aegis-accent/10 text-aegis-accent' : 'border-aegis-border bg-aegis-surface text-aegis-text-dim hover:border-aegis-accent/20' }}">
                                <input type="checkbox" wire:model="selectedSkills" value="{{ $skill->id }}" class="hidden" />
                                {{ $skill->name }}
                            </label>
                        @endforeach
                    </div>
                </div>
            @endif

            @if ($contextBudget)
                <div class="rounded-lg border border-aegis-border bg-aegis-surface p-3 space-y-2">
                    <div class="flex items-center justify-between">
                        <span class="text-xs font-medium text-aegis-text-dim">Context Budget</span>
                        <span class="text-xs text-aegis-text-dim">{{ number_format($contextBudget['total']) }} / {{ number_format($contextBudget['model_limit']) }} tokens</span>
                    </div>
                    <div class="w-full bg-aegis-800 rounded-full h-2 overflow-hidden">
                        @php
                            $pct = $contextBudget['model_limit'] > 0 ? min(100, round(($contextBudget['total'] / $contextBudget['model_limit']) * 100)) : 0;
                        @endphp
                        <div @class([
                            'h-full rounded-full transition-all duration-300',
                            'bg-emerald-400' => $pct <= 20,
                            'bg-amber-400' => $pct > 20 && $pct <= 30,
                            'bg-red-400' => $pct > 30,
                        ]) style="width: {{ $pct }}%"></div>
                    </div>
                    <div class="flex gap-3 text-[10px] text-aegis-text-dim">
                        <span>Base: {{ number_format($contextBudget['base_prompt']) }}</span>
                        <span>Skills: {{ number_format($contextBudget['skills']) }}</span>
                        <span>Projects: {{ number_format($contextBudget['project_context']) }}</span>
                    </div>
                    @if ($contextBudget['warning'])
                        <p class="text-xs text-amber-400">{{ $contextBudget['warning'] }}</p>
                    @endif
                </div>
            @endif

            <div class="flex items-center gap-3">
                <label class="flex items-center gap-2 cursor-pointer">
                    <input type="checkbox" wire:model="isActive" class="rounded border-aegis-border bg-aegis-surface text-aegis-accent focus:ring-aegis-accent/40" />
                    <span class="text-sm text-aegis-text">Active</span>
                </label>
            </div>

            <div class="flex items-center gap-3 pt-2 border-t border-aegis-border">
                @if ($editingAgentId)
                    <button wire:click="updateAgent" class="px-4 py-2 rounded-lg bg-aegis-accent text-aegis-900 text-sm font-medium hover:bg-aegis-glow transition-colors">Save Changes</button>
                @else
                    <button wire:click="createAgent" class="px-4 py-2 rounded-lg bg-aegis-accent text-aegis-900 text-sm font-medium hover:bg-aegis-glow transition-colors">Create Agent</button>
                @endif
                <button wire:click="cancelEdit" class="px-4 py-2 rounded-lg border border-aegis-border text-sm text-aegis-text-dim hover:text-aegis-text hover:bg-aegis-surface-hover transition-all">Cancel</button>
            </div>
        </div>
    @endif

    <div class="space-y-3">
        @forelse ($agents as $agent)
            <div class="rounded-xl border border-aegis-border bg-aegis-850 p-4 flex items-center gap-4">
                <div class="text-2xl leading-none">{{ $agent->avatar ?? '🤖' }}</div>
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-medium text-aegis-text truncate">{{ $agent->name }}</span>
                        <div @class([
                            'w-2 h-2 rounded-full shrink-0',
                            'bg-emerald-400' => $agent->is_active,
                            'bg-aegis-600' => !$agent->is_active,
                        ])></div>
                    </div>
                    <p class="text-xs text-aegis-text-dim mt-0.5">
                        {{ $agent->skills()->count() }} skills
                        · {{ $agent->tools()->count() ?: 'all' }} tools
                    </p>
                </div>
                <div class="flex items-center gap-1.5 shrink-0">
                    <button wire:click="editAgent({{ $agent->id }})" class="p-2 rounded-lg text-aegis-text-dim hover:text-aegis-text hover:bg-aegis-surface-hover transition-all" title="Edit">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    </button>
                    <button wire:click="toggleActive({{ $agent->id }})" class="p-2 rounded-lg text-aegis-text-dim hover:text-aegis-text hover:bg-aegis-surface-hover transition-all" title="Toggle active">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M18.36 6.64a9 9 0 11-12.73 0"/><line x1="12" y1="2" x2="12" y2="12"/></svg>
                    </button>
                    <button wire:click="deleteAgent({{ $agent->id }})" wire:confirm="Delete this agent?" class="p-2 rounded-lg text-aegis-text-dim hover:text-red-300 hover:bg-red-500/10 transition-all" title="Delete">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                    </button>
                </div>
            </div>
        @empty
            <div class="rounded-xl border border-dashed border-aegis-border bg-aegis-850/50 p-8 text-center">
                <p class="text-sm text-aegis-text-dim">No custom agents yet. Create one to get started.</p>
            </div>
        @endforelse
    </div>
</div>
