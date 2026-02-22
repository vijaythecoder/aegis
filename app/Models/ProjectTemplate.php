<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ProjectTemplate extends Model
{
    /** @use HasFactory<\Database\Factories\ProjectTemplateFactory> */
    use HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'description',
        'category',
        'tasks',
        'is_built_in',
    ];

    protected function casts(): array
    {
        return [
            'tasks' => 'array',
            'is_built_in' => 'boolean',
        ];
    }
}
