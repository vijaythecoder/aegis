<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PendingAction extends Model
{
    /** @use HasFactory<\Database\Factories\PendingActionFactory> */
    use HasFactory;

    protected $fillable = [
        'tool_name',
        'tool_params',
        'description',
        'reason',
        'status',
        'conversation_id',
        'delivery_channel',
        'resolved_via',
        'expires_at',
        'resolved_at',
        'result',
    ];

    protected function casts(): array
    {
        return [
            'tool_params' => 'array',
            'expires_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    public function isExpired(): bool
    {
        return $this->isPending()
            && $this->expires_at !== null
            && $this->expires_at->isPast();
    }

    public function approve(string $via = 'chat'): void
    {
        $this->update([
            'status' => 'approved',
            'resolved_via' => $via,
            'resolved_at' => now(),
        ]);
    }

    public function reject(string $via = 'chat'): void
    {
        $this->update([
            'status' => 'rejected',
            'resolved_via' => $via,
            'resolved_at' => now(),
        ]);
    }

    public function markExecuted(string $result): void
    {
        $this->update([
            'status' => 'executed',
            'result' => $result,
        ]);
    }

    public function markFailed(string $error): void
    {
        $this->update([
            'status' => 'failed',
            'result' => $error,
        ]);
    }

    public function expire(): void
    {
        $this->update([
            'status' => 'expired',
            'resolved_at' => now(),
        ]);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeForConversation($query, int $conversationId)
    {
        return $query->where('conversation_id', $conversationId);
    }

    public function scopeExpirable($query)
    {
        return $query->pending()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }
}
