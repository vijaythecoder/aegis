<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Task>
 */
class TaskFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => null,
            'title' => fake()->sentence(),
            'description' => fake()->paragraph(),
            'status' => 'pending',
            'assigned_type' => 'user',
            'assigned_id' => null,
            'priority' => 'medium',
            'deadline' => null,
            'parent_task_id' => null,
            'output' => null,
            'completed_at' => null,
        ];
    }

    public function pending(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'pending',
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'completed_at' => now(),
            'output' => fake()->sentence(),
        ]);
    }

    public function assignedToAgent(): static
    {
        return $this->state(fn (array $attributes) => [
            'assigned_type' => 'agent',
            'assigned_id' => 1,
        ]);
    }

    public function assignedToUser(): static
    {
        return $this->state(fn (array $attributes) => [
            'assigned_type' => 'user',
        ]);
    }

    public function delegated(int $fromTaskId, int $depth = 1): static
    {
        return $this->state(fn (array $attributes) => [
            'delegated_from' => $fromTaskId,
            'delegation_depth' => $depth,
        ]);
    }
}
