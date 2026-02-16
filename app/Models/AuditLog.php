<?php

namespace App\Models;

use App\Enums\AuditLogResult;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversation_id',
        'action',
        'tool_name',
        'parameters',
        'result',
        'ip_address',
        'details',
        'signature',
        'previous_signature',
    ];

    protected function casts(): array
    {
        return [
            'parameters' => 'array',
            'result' => AuditLogResult::class,
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
