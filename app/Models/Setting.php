<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;

class Setting extends Model
{
    protected $fillable = [
        'group',
        'key',
        'value',
        'is_encrypted',
    ];

    protected function casts(): array
    {
        return [
            'is_encrypted' => 'boolean',
        ];
    }

    protected function decryptedValue(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => ($this->is_encrypted && $this->value)
                ? Crypt::decryptString($this->value)
                : $this->value,
        );
    }
}
