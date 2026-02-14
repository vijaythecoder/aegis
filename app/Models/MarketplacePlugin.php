<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MarketplacePlugin extends Model
{
    public const TRUST_VERIFIED = 'verified';

    public const TRUST_COMMUNITY = 'community';

    public const TRUST_UNVERIFIED = 'unverified';

    protected $fillable = [
        'name',
        'version',
        'description',
        'author',
        'downloads',
        'rating',
        'trust_tier',
        'manifest_url',
        'checksum',
    ];

    protected function casts(): array
    {
        return [
            'downloads' => 'integer',
            'rating' => 'float',
        ];
    }

    public function trustTierLabel(): string
    {
        return match ($this->trust_tier) {
            self::TRUST_VERIFIED => 'Verified',
            self::TRUST_COMMUNITY => 'Community',
            default => 'Unverified',
        };
    }
}
