<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProjectKnowledge extends Model
{
    /** @use HasFactory<\Database\Factories\ProjectKnowledgeFactory> */
    use HasFactory;

    protected $table = 'project_knowledge';

    protected $fillable = [
        'project_id',
        'task_id',
        'key',
        'value',
        'type',
    ];

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function task(): BelongsTo
    {
        return $this->belongsTo(Task::class)->withDefault();
    }
}
