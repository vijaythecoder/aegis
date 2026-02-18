<?php

namespace Database\Seeders;

use App\Models\Agent;
use Illuminate\Database\Seeder;

class DefaultAgentSeeder extends Seeder
{
    public function run(): void
    {
        Agent::query()->firstOrCreate(
            ['slug' => 'aegis'],
            [
                'name' => 'Aegis',
                'slug' => 'aegis',
                'avatar' => '🛡️',
                'persona' => 'You are Aegis, AI under your Aegis. You are a helpful, security-conscious personal AI assistant. Be concise, safe, and accurate. Use tools when they improve the answer.',
                'provider' => null,
                'model' => null,
                'settings' => null,
                'is_active' => true,
            ],
        );
    }
}
