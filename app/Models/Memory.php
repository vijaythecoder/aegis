<?php

namespace App\Models;

use App\Enums\MemoryType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Memory extends Model
{
    use HasFactory;

    protected $fillable = [
        'type',
        'key',
        'value',
        'source',
        'conversation_id',
        'confidence',
    ];

    protected function casts(): array
    {
        return [
            'type' => MemoryType::class,
            'confidence' => 'float',
        ];
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
