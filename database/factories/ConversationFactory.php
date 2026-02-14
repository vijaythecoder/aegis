<?php

namespace Database\Factories;

use App\Models\Conversation;
use Illuminate\Database\Eloquent\Factories\Factory;

class ConversationFactory extends Factory
{
    protected $model = Conversation::class;

    public function definition(): array
    {
        return [
            'title' => fake()->sentence(4),
            'summary' => fake()->optional()->sentence(),
            'model' => 'claude-sonnet-4-20250514',
            'provider' => 'anthropic',
            'is_archived' => false,
            'last_message_at' => now(),
        ];
    }
}
