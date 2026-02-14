<?php

namespace Database\Factories;

use App\Models\AuditLog;
use App\Models\Conversation;
use Illuminate\Database\Eloquent\Factories\Factory;

class AuditLogFactory extends Factory
{
    protected $model = AuditLog::class;

    public function definition(): array
    {
        return [
            'conversation_id' => Conversation::factory(),
            'action' => fake()->randomElement(['tool.request', 'tool.execute', 'tool.result']),
            'tool_name' => fake()->optional()->word(),
            'parameters' => ['input' => fake()->sentence()],
            'result' => fake()->randomElement(['allowed', 'denied', 'pending', 'error']),
            'ip_address' => fake()->ipv4(),
            'details' => fake()->optional()->sentence(),
        ];
    }
}
