<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Task extends Model
{
    /** @use HasFactory<\Database\Factories\TaskFactory> */
    use HasFactory;

    protected $fillable = [
        'project_id',
        'title',
        'description',
        'status',
        'assigned_type',
        'assigned_id',
        'priority',
        'deadline',
        'parent_task_id',
        'output',
        'completed_at',
        'delegation_depth',
        'delegated_from',
    ];

    protected function casts(): array
    {
        return [
            'deadline' => 'datetime',
            'completed_at' => 'datetime',
            'delegation_depth' => 'integer',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withDefault();
    }

    public function subtasks(): HasMany
    {
        return $this->hasMany(Task::class, 'parent_task_id');
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'parent_task_id')->withDefault();
    }

    public function delegatedFromTask(): BelongsTo
    {
        return $this->belongsTo(Task::class, 'delegated_from');
    }

    public function delegatedTasks(): HasMany
    {
        return $this->hasMany(Task::class, 'delegated_from');
    }

    /**
     * @return Collection<int, Task>
     */
    public function getDelegationChain(): Collection
    {
        $chain = new Collection;
        $current = $this;

        while ($current->delegated_from !== null) {
            $parent = Task::query()->find($current->delegated_from);

            if (! $parent instanceof Task) {
                break;
            }

            $chain->push($parent);
            $current = $parent;
        }

        return $chain;
    }

    public function hasAgentInDelegationChain(int $agentId): bool
    {
        if ($this->assigned_type === 'agent' && (int) $this->assigned_id === $agentId) {
            return true;
        }

        foreach ($this->getDelegationChain() as $task) {
            if ($task->assigned_type === 'agent' && (int) $task->assigned_id === $agentId) {
                return true;
            }
        }

        return false;
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeForAgent($query, int $agentId)
    {
        return $query->where('assigned_type', 'agent')->where('assigned_id', $agentId);
    }
}
