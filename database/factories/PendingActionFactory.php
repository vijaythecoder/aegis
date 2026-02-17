<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\PendingAction>
 */
class PendingActionFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tool_name' => 'shell',
            'tool_params' => ['command' => 'echo hello'],
            'description' => fake()->sentence(),
            'reason' => fake()->sentence(),
            'status' => 'pending',
            'delivery_channel' => 'chat',
            'expires_at' => now()->addHours(24),
        ];
    }

    public function approved(): static
    {
        return $this->state(fn () => [
            'status' => 'approved',
            'resolved_via' => 'chat',
            'resolved_at' => now(),
        ]);
    }

    public function rejected(): static
    {
        return $this->state(fn () => [
            'status' => 'rejected',
            'resolved_via' => 'chat',
            'resolved_at' => now(),
        ]);
    }

    public function expired(): static
    {
        return $this->state(fn () => [
            'status' => 'pending',
            'expires_at' => now()->subHour(),
        ]);
    }
}
