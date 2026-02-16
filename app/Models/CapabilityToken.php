<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CapabilityToken extends Model
{
    protected $fillable = [
        'capability',
        'scope',
        'issuer',
        'expires_at',
        'revoked',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked' => 'boolean',
        ];
    }

    public function isValid(): bool
    {
        if ($this->revoked) {
            return false;
        }

        if ($this->expires_at !== null && $this->expires_at->isPast()) {
            return false;
        }

        return true;
    }

    public function matches(string $capability, ?string $scope = null): bool
    {
        if (! $this->isValid()) {
            return false;
        }

        if ($this->capability !== $capability) {
            return false;
        }

        if ($this->scope === null || $this->scope === '*') {
            return true;
        }

        if ($scope === null) {
            return true;
        }

        return fnmatch($this->scope, $scope);
    }

    public function revoke(): bool
    {
        return $this->update(['revoked' => true]);
    }
}
