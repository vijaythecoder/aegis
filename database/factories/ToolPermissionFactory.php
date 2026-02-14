<?php

namespace Database\Factories;

use App\Models\ToolPermission;
use Illuminate\Database\Eloquent\Factories\Factory;

class ToolPermissionFactory extends Factory
{
    protected $model = ToolPermission::class;

    public function definition(): array
    {
        return [
            'tool_name' => fake()->word(),
            'scope' => fake()->optional()->randomElement(['session', 'conversation', 'global']),
            'permission' => fake()->randomElement(['allow', 'deny', 'ask']),
            'expires_at' => fake()->optional()->dateTimeBetween('now', '+7 days'),
        ];
    }
}
