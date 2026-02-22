<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Project extends Model
{
    /** @use HasFactory<\Database\Factories\ProjectFactory> */
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
        'status',
        'category',
        'deadline',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
            'deadline' => 'datetime',
        ];
    }

    public function tasks(): HasMany
    {
        return $this->hasMany(Task::class);
    }

    public function knowledge(): HasMany
    {
        return $this->hasMany(ProjectKnowledge::class);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', 'completed');
    }

    public static function fromTemplate(ProjectTemplate $template, array $overrides = []): static
    {
        $project = static::query()->create(array_merge([
            'title' => $template->name,
            'description' => $template->description,
            'category' => $template->category,
            'status' => 'active',
        ], $overrides));

        foreach ($template->tasks as $taskData) {
            Task::query()->create([
                'project_id' => $project->id,
                'title' => $taskData['title'],
                'description' => $taskData['description'] ?? null,
                'assigned_type' => $taskData['assigned_type'] ?? 'user',
                'priority' => $taskData['priority'] ?? 'medium',
                'status' => 'pending',
            ]);
        }

        return $project->load('tasks');
    }
}
