<?php

namespace Database\Factories;

use App\Models\ProactiveTask;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProactiveTaskRun>
 */
class ProactiveTaskRunFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $started = now()->subMinutes(fake()->numberBetween(1, 60));

        return [
            'proactive_task_id' => ProactiveTask::factory(),
            'status' => 'success',
            'started_at' => $started,
            'completed_at' => $started->copy()->addSeconds(fake()->numberBetween(2, 30)),
            'response_summary' => fake()->sentence(),
            'tokens_used' => fake()->numberBetween(100, 2000),
            'estimated_cost' => fake()->randomFloat(8, 0.0001, 0.05),
            'error_message' => null,
            'delivery_status' => 'sent',
        ];
    }

    public function failed(): static
    {
        return $this->state(fn () => [
            'status' => 'failed',
            'response_summary' => null,
            'tokens_used' => 0,
            'estimated_cost' => 0,
            'error_message' => fake()->sentence(),
            'delivery_status' => 'failed',
        ]);
    }

    public function pending(): static
    {
        return $this->state(fn () => [
            'delivery_status' => 'pending',
        ]);
    }
}
