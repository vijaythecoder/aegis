<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ChannelAgent extends Model
{
    /** @use HasFactory<\Database\Factories\ChannelAgentFactory> */
    use HasFactory;

    protected $fillable = [
        'channel',
        'agent_id',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(Agent::class);
    }

    public static function forChannel(string $channel): ?self
    {
        return static::query()->where('channel', $channel)->first();
    }
}
