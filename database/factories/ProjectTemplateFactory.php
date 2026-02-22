<?php

namespace Database\Factories;

use App\Models\ProjectTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ProjectTemplate>
 */
class ProjectTemplateFactory extends Factory
{
    protected $model = ProjectTemplate::class;

    public function definition(): array
    {
        $name = fake()->unique()->words(3, true);

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
            'category' => fake()->randomElement(['finance', 'health', 'education', 'home', 'work']),
            'tasks' => [
                ['title' => 'Step 1', 'description' => 'First step', 'assigned_type' => 'user', 'priority' => 'medium', 'order' => 1],
                ['title' => 'Step 2', 'description' => 'Second step', 'assigned_type' => 'user', 'priority' => 'medium', 'order' => 2],
            ],
            'is_built_in' => false,
        ];
    }

    public function builtIn(): static
    {
        return $this->state(fn (array $attributes): array => [
            'is_built_in' => true,
        ]);
    }
}
