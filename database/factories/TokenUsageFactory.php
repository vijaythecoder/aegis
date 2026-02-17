<?php

namespace Database\Factories;

use App\Models\Conversation;
use App\Models\TokenUsage;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TokenUsage>
 */
class TokenUsageFactory extends Factory
{
    protected $model = TokenUsage::class;

    public function definition(): array
    {
        $promptTokens = fake()->numberBetween(50, 5000);
        $completionTokens = fake()->numberBetween(20, 2000);

        return [
            'conversation_id' => Conversation::factory(),
            'message_id' => null,
            'agent_class' => 'App\\Agent\\AegisAgent',
            'provider' => fake()->randomElement(['anthropic', 'openai', 'gemini', 'deepseek', 'groq']),
            'model' => fake()->randomElement([
                'claude-sonnet-4-20250514',
                'gpt-4o',
                'gemini-2.5-pro',
                'deepseek-chat',
                'llama-3.3-70b-versatile',
            ]),
            'prompt_tokens' => $promptTokens,
            'completion_tokens' => $completionTokens,
            'cache_read_tokens' => fake()->numberBetween(0, 500),
            'cache_write_tokens' => fake()->numberBetween(0, 200),
            'reasoning_tokens' => fake()->numberBetween(0, 300),
            'total_tokens' => $promptTokens + $completionTokens,
            'estimated_cost' => fake()->randomFloat(6, 0.0001, 0.5),
            'currency' => 'USD',
        ];
    }

    public function forProvider(string $provider, string $model): static
    {
        return $this->state(fn (): array => [
            'provider' => $provider,
            'model' => $model,
        ]);
    }

    public function free(): static
    {
        return $this->state(fn (): array => [
            'estimated_cost' => 0,
            'provider' => 'ollama',
            'model' => 'llama3.2',
        ]);
    }
}
