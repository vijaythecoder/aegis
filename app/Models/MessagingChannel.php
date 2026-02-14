<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MessagingChannel extends Model
{
    use HasFactory;

    protected $fillable = [
        'platform',
        'platform_channel_id',
        'platform_user_id',
        'conversation_id',
        'config',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'config' => 'array',
            'active' => 'boolean',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
