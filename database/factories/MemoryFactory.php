<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Memory;
use Illuminate\Database\Eloquent\Factories\Factory;

class MemoryFactory extends Factory
{
    protected $model = Memory::class;

    public function definition(): array
    {
        return [
            'type' => fake()->randomElement(['fact', 'preference', 'note']),
            'key' => fake()->unique()->slug(2),
            'value' => fake()->sentence(),
            'source' => 'agent',
            'conversation_id' => Conversation::factory(),
            'confidence' => fake()->randomFloat(2, 0.50, 1.00),
        ];
    }
}
