<?php

namespace App\Models;

use Cron\CronExpression;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProactiveTask extends Model
{
    /** @use HasFactory<\Database\Factories\ProactiveTaskFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'schedule',
        'prompt',
        'delivery_channel',
        'is_active',
        'last_run_at',
        'next_run_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_run_at' => 'datetime',
            'next_run_at' => 'datetime',
        ];
    }

    public function isDue(): bool
    {
        if (! $this->is_active) {
            return false;
        }

        if ($this->next_run_at === null) {
            return true;
        }

        return $this->next_run_at->lte(now());
    }

    public function updateNextRun(): void
    {
        $cron = new CronExpression($this->schedule);
        $this->update([
            'last_run_at' => now(),
            'next_run_at' => $cron->getNextRunDate(),
        ]);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeDue($query)
    {
        return $query->active()
            ->where(function ($q) {
                $q->whereNull('next_run_at')
                    ->orWhere('next_run_at', '<=', now());
            });
    }
}
