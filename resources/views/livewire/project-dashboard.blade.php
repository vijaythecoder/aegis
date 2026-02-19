<div class="flex-1 overflow-y-auto p-6 space-y-6">

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

    {{-- Project Header --}}
    <div class="bg-aegis-850 border border-aegis-border rounded-xl p-6">
        <div class="flex items-start justify-between gap-4">
            <div class="flex-1 min-w-0">
                <div class="flex items-center gap-3 mb-1">
                    <h2 class="text-xl font-display font-bold text-aegis-text truncate">{{ $project->title }}</h2>
                    <span @class([
                        'px-2 py-0.5 rounded-full text-[11px] font-medium uppercase tracking-wider shrink-0',
                        'bg-emerald-500/15 text-emerald-400' => $project->status === 'active',
                        'bg-amber-500/15 text-amber-400' => $project->status === 'paused',
                        'bg-blue-500/15 text-blue-400' => $project->status === 'completed',
                        'bg-aegis-600/30 text-aegis-text-dim' => $project->status === 'archived',
                    ])>{{ $project->status }}</span>
                </div>
                @if ($project->description)
                    <p class="text-sm text-aegis-text-dim mt-1">{{ $project->description }}</p>
                @endif
                <div class="flex items-center gap-4 mt-3 text-xs text-aegis-text-dim">
                    @if ($project->category)
                        <span class="flex items-center gap-1">
                            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 01-2 2H4a2 2 0 01-2-2V5a2 2 0 012-2h5l2 3h9a2 2 0 012 2z"/></svg>
                            {{ $project->category }}
                        </span>
                    @endif
                    @if ($project->deadline)
                        <span class="flex items-center gap-1">
                            <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
                            Due {{ $project->deadline->format('M j, Y') }}
                        </span>
                    @endif
                    <span>{{ $project->completed_tasks_count }}/{{ $project->tasks_count }} tasks done</span>
                </div>
            </div>
            <a href="{{ route('chat') }}" class="shrink-0 px-3 py-1.5 rounded-lg text-xs font-medium text-aegis-text-dim hover:text-aegis-text hover:bg-aegis-surface-hover border border-aegis-border transition-all duration-150">
                Back to Chat
            </a>
        </div>

        {{-- Progress Bar --}}
        @if ($project->tasks_count > 0)
            <div class="mt-4">
                <div class="flex items-center justify-between text-xs text-aegis-text-dim mb-1.5">
                    <span>Progress</span>
                    <span>{{ $progressPercent }}%</span>
                </div>
                <div class="w-full h-2 rounded-full bg-aegis-900/60 overflow-hidden">
                    <div
                        class="h-full rounded-full bg-gradient-to-r from-aegis-accent to-aegis-glow transition-all duration-500"
                        style="width: {{ $progressPercent }}%"
                    ></div>
                </div>
            </div>
        @endif
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

        {{-- Tasks Panel --}}
        <div class="lg:col-span-2 space-y-4">
            <div class="flex items-center justify-between">
                <h3 class="text-sm font-semibold text-aegis-text">Tasks</h3>
                <select
                    wire:model.live="statusFilter"
                    class="bg-aegis-850 border border-aegis-border rounded-lg px-2 py-1 text-xs text-aegis-text focus:outline-none focus:border-aegis-accent/40"
                >
                    <option value="">All</option>
                    <option value="pending">Pending</option>
                    <option value="in_progress">In Progress</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </div>

            {{-- Add Task Form --}}
            <div class="flex items-center gap-2">
                <input
                    wire:model="newTaskTitle"
                    wire:keydown.enter="createTask"
                    type="text"
                    placeholder="Add a task..."
                    class="flex-1 bg-aegis-850 border border-aegis-border rounded-lg px-3 py-2 text-sm text-aegis-text placeholder-aegis-text-dim focus:outline-none focus:border-aegis-accent/40 transition-colors"
                />
                <select
                    wire:model="newTaskPriority"
                    class="bg-aegis-850 border border-aegis-border rounded-lg px-2 py-2 text-xs text-aegis-text focus:outline-none focus:border-aegis-accent/40"
                >
                    <option value="low">Low</option>
                    <option value="medium">Medium</option>
                    <option value="high">High</option>
                </select>
                <button
                    wire:click="createTask"
                    class="px-3 py-2 rounded-lg bg-aegis-accent text-aegis-900 text-sm font-medium hover:bg-aegis-glow transition-colors"
                >
                    Add
                </button>
            </div>

            @error('newTaskTitle')
                <p class="text-xs text-red-400">{{ $message }}</p>
            @enderror

            {{-- Task List --}}
            <div class="space-y-1">
                @forelse ($tasks as $task)
                    <div class="group flex items-center gap-3 px-3 py-2.5 rounded-lg bg-aegis-850 border border-aegis-border hover:border-aegis-accent/20 transition-all duration-150">
                        {{-- Status Toggle --}}
                        @if ($task->status !== 'completed')
                            <button
                                wire:click="completeTask({{ $task->id }})"
                                class="shrink-0 w-5 h-5 rounded-full border-2 border-aegis-text-dim/30 hover:border-aegis-accent transition-colors"
                                title="Mark complete"
                            ></button>
                        @else
                            <div class="shrink-0 w-5 h-5 rounded-full bg-emerald-500/20 border-2 border-emerald-500/40 flex items-center justify-center">
                                <svg class="w-3 h-3 text-emerald-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>
                            </div>
                        @endif

                        {{-- Task Content --}}
                        <div class="flex-1 min-w-0">
                            <span @class([
                                'text-sm',
                                'text-aegis-text' => $task->status !== 'completed',
                                'text-aegis-text-dim line-through' => $task->status === 'completed',
                            ])>{{ $task->title }}</span>
                            @if ($task->description)
                                <p class="text-xs text-aegis-text-dim/60 truncate mt-0.5">{{ $task->description }}</p>
                            @endif
                        </div>

                        {{-- Priority Badge --}}
                        @if ($task->priority !== 'medium')
                            <span @class([
                                'shrink-0 px-1.5 py-0.5 rounded text-[10px] font-medium uppercase',
                                'bg-red-500/15 text-red-400' => $task->priority === 'high',
                                'bg-aegis-600/30 text-aegis-text-dim' => $task->priority === 'low',
                            ])>{{ $task->priority }}</span>
                        @endif

                        {{-- Status Badge (if not pending/completed) --}}
                        @if ($task->status === 'in_progress')
                            <span class="shrink-0 px-1.5 py-0.5 rounded text-[10px] font-medium uppercase bg-blue-500/15 text-blue-400">active</span>
                        @elseif ($task->status === 'cancelled')
                            <span class="shrink-0 px-1.5 py-0.5 rounded text-[10px] font-medium uppercase bg-aegis-600/30 text-aegis-text-dim">cancelled</span>
                        @endif

                        {{-- Deadline --}}
                        @if ($task->deadline)
                            <span class="shrink-0 text-[10px] text-aegis-text-dim">{{ $task->deadline->format('M j') }}</span>
                        @endif

                        {{-- Actions --}}
                        <div class="shrink-0 opacity-0 group-hover:opacity-100 transition-opacity flex items-center gap-1">
                            @if ($task->status !== 'in_progress' && $task->status !== 'completed')
                                <button
                                    wire:click="updateTaskStatus({{ $task->id }}, 'in_progress')"
                                    class="p-1 rounded text-aegis-text-dim hover:text-blue-400 transition-colors"
                                    title="Start task"
                                >
                                    <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="5 3 19 12 5 21 5 3"/></svg>
                                </button>
                            @endif
                            <button
                                wire:click="deleteTask({{ $task->id }})"
                                wire:confirm="Delete this task?"
                                class="p-1 rounded text-aegis-text-dim hover:text-red-400 transition-colors"
                                title="Delete task"
                            >
                                <svg class="w-3.5 h-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 01-2 2H7a2 2 0 01-2-2V6m3 0V4a2 2 0 012-2h4a2 2 0 012 2v2"/></svg>
                            </button>
                        </div>
                    </div>
                @empty
                    <div class="text-center py-8 text-aegis-text-dim text-sm">
                        No tasks yet. Add one above to get started.
                    </div>
                @endforelse
            </div>
        </div>

        {{-- Right Sidebar --}}
        <div class="space-y-4">

            {{-- Project Details Card --}}
            <div class="bg-aegis-850 border border-aegis-border rounded-xl p-4 space-y-3">
                <h4 class="text-xs font-semibold uppercase tracking-wider text-aegis-text-dim">Edit Project</h4>

                <div>
                    <label class="block text-xs text-aegis-text-dim mb-1">Title</label>
                    <input wire:model="projectTitle" type="text" class="w-full bg-aegis-900/60 border border-aegis-border rounded-lg px-3 py-1.5 text-sm text-aegis-text focus:outline-none focus:border-aegis-accent/40 transition-colors" />
                    @error('projectTitle') <p class="text-xs text-red-400 mt-1">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="block text-xs text-aegis-text-dim mb-1">Description</label>
                    <textarea wire:model="projectDescription" rows="3" class="w-full bg-aegis-900/60 border border-aegis-border rounded-lg px-3 py-1.5 text-sm text-aegis-text focus:outline-none focus:border-aegis-accent/40 transition-colors resize-none"></textarea>
                </div>

                <div class="grid grid-cols-2 gap-2">
                    <div>
                        <label class="block text-xs text-aegis-text-dim mb-1">Status</label>
                        <select wire:model="projectStatus" class="w-full bg-aegis-900/60 border border-aegis-border rounded-lg px-2 py-1.5 text-sm text-aegis-text focus:outline-none focus:border-aegis-accent/40">
                            <option value="active">Active</option>
                            <option value="paused">Paused</option>
                            <option value="completed">Completed</option>
                            <option value="archived">Archived</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs text-aegis-text-dim mb-1">Category</label>
                        <input wire:model="projectCategory" type="text" class="w-full bg-aegis-900/60 border border-aegis-border rounded-lg px-3 py-1.5 text-sm text-aegis-text focus:outline-none focus:border-aegis-accent/40 transition-colors" placeholder="e.g. finance" />
                    </div>
                </div>

                <div>
                    <label class="block text-xs text-aegis-text-dim mb-1">Deadline</label>
                    <input wire:model="projectDeadline" type="date" class="w-full bg-aegis-900/60 border border-aegis-border rounded-lg px-3 py-1.5 text-sm text-aegis-text focus:outline-none focus:border-aegis-accent/40 transition-colors" />
                </div>

                <button wire:click="updateProject" class="w-full px-3 py-2 rounded-lg bg-aegis-accent text-aegis-900 text-sm font-medium hover:bg-aegis-glow transition-colors">
                    Save Changes
                </button>
            </div>

            {{-- Knowledge Panel --}}
            @if ($knowledge->isNotEmpty())
                <div class="bg-aegis-850 border border-aegis-border rounded-xl p-4 space-y-3">
                    <h4 class="text-xs font-semibold uppercase tracking-wider text-aegis-text-dim">Project Knowledge</h4>
                    <div class="space-y-2">
                        @foreach ($knowledge as $entry)
                            <div class="text-sm">
                                <span class="font-medium text-aegis-text">{{ $entry->key }}:</span>
                                <span class="text-aegis-text-dim ml-1">{{ Str::limit($entry->value, 200) }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

        </div>
    </div>
</div>
