<?php

namespace App\Models;

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
    ];

    protected function casts(): array
    {
        return [
            'deadline' => 'datetime',
            'completed_at' => 'datetime',
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

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeForAgent($query, int $agentId)
    {
        return $query->where('assigned_type', 'agent')->where('assigned_id', $agentId);
    }
}
