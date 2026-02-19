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
            <h3 class="text-lg font-display font-bold text-aegis-text">Skill Library</h3>
            <p class="text-sm text-aegis-text-dim mt-1">Reusable knowledge packages for your agents.</p>
        </div>
        @if (!$showForm && !$viewingSkillId)
            <button
                wire:click="newSkill"
                class="flex items-center gap-2 px-4 py-2 rounded-lg text-sm font-medium bg-aegis-accent text-aegis-900 hover:bg-aegis-glow transition-all duration-150"
            >
                <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round">
                    <line x1="12" y1="5" x2="12" y2="19"/>
                    <line x1="5" y1="12" x2="19" y2="12"/>
                </svg>
                Create Custom Skill
            </button>
        @endif
    </div>

    {{-- Skill Detail View --}}
    @if ($viewingSkill)
        <div class="rounded-xl border border-aegis-border bg-aegis-850 p-6 space-y-4">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <h4 class="text-sm font-semibold text-aegis-text">{{ $viewingSkill->name }}</h4>
                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-aegis-accent/10 text-aegis-accent border border-aegis-accent/20">
                        {{ $viewingSkill->category ?? 'general' }}
                    </span>
                    @if ($viewingSkill->source === 'built_in')
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-sky-500/10 text-sky-300 border border-sky-400/20">
                            Built-in
                        </span>
                    @endif
                </div>
                <button wire:click="closeView" class="p-2 rounded-lg text-aegis-text-dim hover:text-aegis-text hover:bg-aegis-surface-hover transition-all" title="Close">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>
                </button>
            </div>

            @if ($viewingSkill->description)
                <p class="text-sm text-aegis-text-dim">{{ $viewingSkill->description }}</p>
            @endif

            <p class="text-xs text-aegis-text-dim">Used by {{ $viewingSkill->agents_count }} {{ Str::plural('agent', $viewingSkill->agents_count) }}</p>

            <div class="border-t border-aegis-border pt-4">
                <label class="block text-xs font-medium text-aegis-text-dim mb-2">Instructions</label>
                <div class="bg-aegis-surface rounded-lg p-4 text-sm text-aegis-text whitespace-pre-wrap max-h-80 overflow-y-auto">{{ $viewingSkill->instructions }}</div>
            </div>
        </div>
    @endif

    {{-- Create / Edit Form --}}
    @if ($showForm)
        <div class="rounded-xl border border-aegis-border bg-aegis-850 p-6 space-y-5">
            <h4 class="text-sm font-semibold text-aegis-text">{{ $editingSkillId ? 'Edit Skill' : 'Create Custom Skill' }}</h4>

            <div class="grid grid-cols-[1fr_auto] gap-4">
                <div>
                    <label class="block text-xs font-medium text-aegis-text-dim mb-1.5">Name</label>
                    <input wire:model="name" type="text" maxlength="100" placeholder="e.g. Nutrition Expert" class="w-full bg-aegis-surface border border-aegis-border rounded-lg px-3 py-2 text-sm text-aegis-text placeholder-aegis-text-dim focus:outline-none focus:border-aegis-accent/40 transition-colors" />
                    @error('name') <span class="text-xs text-red-400 mt-1">{{ $message }}</span> @enderror
                </div>
                <div>
                    <label class="block text-xs font-medium text-aegis-text-dim mb-1.5">Category</label>
                    <select wire:model="category" class="bg-aegis-surface border border-aegis-border rounded-lg px-3 py-2 text-sm text-aegis-text focus:outline-none focus:border-aegis-accent/40 transition-colors">
                        @foreach ($categories as $cat)
                            <option value="{{ $cat }}">{{ ucfirst($cat) }}</option>
                        @endforeach
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-medium text-aegis-text-dim mb-1.5">Description <span class="text-aegis-text-dim/50">(optional)</span></label>
                <input wire:model="description" type="text" maxlength="255" placeholder="Brief description of what this skill provides..." class="w-full bg-aegis-surface border border-aegis-border rounded-lg px-3 py-2 text-sm text-aegis-text placeholder-aegis-text-dim focus:outline-none focus:border-aegis-accent/40 transition-colors" />
            </div>

            <div x-data="{ count: $wire.instructions.length }">
                <div class="flex items-center justify-between mb-1.5">
                    <label class="block text-xs font-medium text-aegis-text-dim">Instructions</label>
                    <span
                        x-text="count.toLocaleString() + ' / 15,000'"
                        :class="count > 12000 ? (count > 15000 ? 'text-red-400' : 'text-amber-400') : 'text-aegis-text-dim'"
                        class="text-xs transition-colors"
                    ></span>
                </div>
                <textarea
                    wire:model="instructions"
                    x-on:input="count = $event.target.value.length"
                    rows="10"
                    maxlength="15000"
                    placeholder="Write the knowledge and instructions for this skill in markdown format..."
                    class="w-full bg-aegis-surface border border-aegis-border rounded-lg px-3 py-2 text-sm text-aegis-text placeholder-aegis-text-dim focus:outline-none focus:border-aegis-accent/40 transition-colors resize-none font-mono"
                ></textarea>
                @error('instructions') <span class="text-xs text-red-400 mt-1">{{ $message }}</span> @enderror
            </div>

            <div class="flex items-center gap-3 pt-2 border-t border-aegis-border">
                @if ($editingSkillId)
                    <button wire:click="updateSkill" class="px-4 py-2 rounded-lg bg-aegis-accent text-aegis-900 text-sm font-medium hover:bg-aegis-glow transition-colors">Save Changes</button>
                @else
                    <button wire:click="createSkill" class="px-4 py-2 rounded-lg bg-aegis-accent text-aegis-900 text-sm font-medium hover:bg-aegis-glow transition-colors">Create Skill</button>
                @endif
                <button wire:click="cancelEdit" class="px-4 py-2 rounded-lg border border-aegis-border text-sm text-aegis-text-dim hover:text-aegis-text hover:bg-aegis-surface-hover transition-all">Cancel</button>
            </div>
        </div>
    @endif

    {{-- Built-in Skills --}}
    @if ($builtInSkills->isNotEmpty())
        <div class="space-y-3">
            <h4 class="text-xs font-semibold text-aegis-text-dim uppercase tracking-wider">Built-in Skills</h4>
            @foreach ($builtInSkills as $skill)
                <div
                    wire:click="viewSkill({{ $skill->id }})"
                    class="rounded-xl border border-aegis-border bg-aegis-850 p-4 flex items-center gap-4 cursor-pointer hover:border-aegis-accent/20 transition-all"
                >
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-2">
                            <span class="text-sm font-medium text-aegis-text truncate">{{ $skill->name }}</span>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-aegis-accent/10 text-aegis-accent border border-aegis-accent/20 shrink-0">
                                {{ $skill->category ?? 'general' }}
                            </span>
                        </div>
                        <p class="text-xs text-aegis-text-dim mt-0.5 truncate">{{ $skill->description }}</p>
                    </div>
                    <span class="text-xs text-aegis-text-dim shrink-0">{{ $skill->agents_count }} {{ Str::plural('agent', $skill->agents_count) }}</span>
                </div>
            @endforeach
        </div>
    @endif

    {{-- Custom Skills --}}
    <div class="space-y-3">
        <h4 class="text-xs font-semibold text-aegis-text-dim uppercase tracking-wider">Custom Skills</h4>
        @forelse ($customSkills as $skill)
            <div class="rounded-xl border border-aegis-border bg-aegis-850 p-4 flex items-center gap-4">
                <div wire:click="viewSkill({{ $skill->id }})" class="flex-1 min-w-0 cursor-pointer">
                    <div class="flex items-center gap-2">
                        <span class="text-sm font-medium text-aegis-text truncate">{{ $skill->name }}</span>
                        <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium bg-aegis-accent/10 text-aegis-accent border border-aegis-accent/20 shrink-0">
                            {{ $skill->category ?? 'general' }}
                        </span>
                    </div>
                    <p class="text-xs text-aegis-text-dim mt-0.5 truncate">{{ $skill->description }}</p>
                </div>
                <div class="flex items-center gap-1.5 shrink-0">
                    <span class="text-xs text-aegis-text-dim mr-2">{{ $skill->agents_count }} {{ Str::plural('agent', $skill->agents_count) }}</span>
                    <button wire:click="editSkill({{ $skill->id }})" class="p-2 rounded-lg text-aegis-text-dim hover:text-aegis-text hover:bg-aegis-surface-hover transition-all" title="Edit">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M11 4H4a2 2 0 00-2 2v14a2 2 0 002 2h14a2 2 0 002-2v-7"/><path d="M18.5 2.5a2.121 2.121 0 013 3L12 15l-4 1 1-4 9.5-9.5z"/></svg>
                    </button>
                    <button wire:click="deleteSkill({{ $skill->id }})" wire:confirm="Delete this skill?" class="p-2 rounded-lg text-aegis-text-dim hover:text-red-300 hover:bg-red-500/10 transition-all" title="Delete">
                        <svg class="w-4 h-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                    </button>
                </div>
            </div>
        @empty
            <div class="rounded-xl border border-dashed border-aegis-border bg-aegis-850/50 p-8 text-center">
                <p class="text-sm text-aegis-text-dim">No custom skills yet. Create one to add specialized knowledge to your agents.</p>
            </div>
        @endforelse
    </div>
</div>
