<?php

namespace Database\Factories;

use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\ProjectKnowledge>
 */
class ProjectKnowledgeFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'task_id' => null,
            'key' => fake()->word(),
            'value' => fake()->paragraph(),
            'type' => 'note',
        ];
    }
}
