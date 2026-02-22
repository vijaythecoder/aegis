<?php

namespace Database\Factories;

use App\Models\Agent;
use App\Models\ChannelAgent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ChannelAgent>
 */
class ChannelAgentFactory extends Factory
{
    protected $model = ChannelAgent::class;

    public function definition(): array
    {
        return [
            'channel' => fake()->unique()->randomElement(['telegram', 'discord', 'imessage', 'slack', 'whatsapp', 'signal']),
            'agent_id' => Agent::factory(),
        ];
    }
}
