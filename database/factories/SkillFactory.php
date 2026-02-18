<?php

namespace Database\Factories;

use App\Models\Skill;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Skill>
 */
class SkillFactory extends Factory
{
    protected $model = Skill::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->words(2, true);

        return [
            'name' => $name,
            'slug' => Str::slug($name),
            'description' => fake()->sentence(),
            'instructions' => fake()->paragraphs(3, true),
            'category' => fake()->randomElement(['productivity', 'finance', 'health', 'education']),
            'source' => 'user_created',
            'version' => '1.0',
            'is_active' => true,
            'metadata' => null,
        ];
    }

    public function builtIn(): static
    {
        return $this->state(fn (array $attributes) => [
            'source' => 'built_in',
        ]);
    }

    public function userCreated(): static
    {
        return $this->state(fn (array $attributes) => [
            'source' => 'user_created',
        ]);
    }

    public function marketplace(): static
    {
        return $this->state(fn (array $attributes) => [
            'source' => 'marketplace',
        ]);
    }
}
