<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProactiveTask>
 */
class ProactiveTaskFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => fake()->slug(2),
            'schedule' => '0 8 * * *',
            'prompt' => fake()->sentence(),
            'delivery_channel' => 'chat',
            'is_active' => false,
            'last_run_at' => null,
            'next_run_at' => null,
        ];
    }

    public function active(): static
    {
        return $this->state(fn () => [
            'is_active' => true,
        ]);
    }

    public function due(): static
    {
        return $this->active()->state(fn () => [
            'next_run_at' => now()->subMinute(),
        ]);
    }

    public function notDue(): static
    {
        return $this->active()->state(fn () => [
            'next_run_at' => now()->addHour(),
        ]);
    }
}
