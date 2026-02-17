<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProactiveTaskRun extends Model
{
    /** @use HasFactory<\Database\Factories\ProactiveTaskRunFactory> */
    use HasFactory;

    protected $fillable = [
        'proactive_task_id',
        'status',
        'started_at',
        'completed_at',
        'response_summary',
        'tokens_used',
        'estimated_cost',
        'error_message',
        'delivery_status',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'tokens_used' => 'integer',
            'estimated_cost' => 'decimal:8',
        ];
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(ProactiveTask::class, 'proactive_task_id');
    }

    public function durationInSeconds(): ?float
    {
        if ($this->started_at === null || $this->completed_at === null) {
            return null;
        }

        return (float) abs($this->completed_at->diffInSeconds($this->started_at));
    }

    public function scopeSuccessful($query)
    {
        return $query->where('status', 'success');
    }

    public function scopeFailed($query)
    {
        return $query->where('status', 'failed');
    }

    public function scopeToday($query)
    {
        return $query->whereDate('created_at', today());
    }
}
