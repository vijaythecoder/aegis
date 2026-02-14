<?php

namespace App\Models;

use App\Enums\ToolPermissionLevel;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ToolPermission extends Model
{
    use HasFactory;

    protected $fillable = [
        'tool_name',
        'scope',
        'permission',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'permission' => ToolPermissionLevel::class,
            'expires_at' => 'datetime',
        ];
    }

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isAllowed(): bool
    {
        return ! $this->isExpired() && $this->permission === ToolPermissionLevel::Allow;
    }
}
