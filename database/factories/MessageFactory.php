<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Database\Eloquent\Factories\Factory;

class MessageFactory extends Factory
{
    protected $model = Message::class;

    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'role' => fake()->randomElement(['system', 'user', 'assistant', 'tool']),
            'content' => fake()->paragraph(),
            'tool_name' => null,
            'tool_call_id' => null,
            'tool_result' => null,
            'tokens_used' => fake()->numberBetween(10, 800),
        ];
    }
}
