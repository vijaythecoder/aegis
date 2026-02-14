<?php

namespace App\Security;

use InvalidArgumentException;

class ProviderConfig
{
    public const PROVIDERS = [
        'anthropic' => [
            'name' => 'Anthropic (Claude)',
            'key_prefix' => 'sk-ant-',
            'key_pattern' => '/^sk-ant-[a-zA-Z0-9_-]{20,}$/',
            'env_var' => 'ANTHROPIC_API_KEY',
        ],
        'openai' => [
            'name' => 'OpenAI (GPT)',
            'key_prefix' => 'sk-',
            'key_pattern' => '/^sk-[a-zA-Z0-9_-]{20,}$/',
            'env_var' => 'OPENAI_API_KEY',
        ],
        'gemini' => [
            'name' => 'Google (Gemini)',
            'key_prefix' => 'AI',
            'key_pattern' => '/^AI[a-zA-Z0-9_-]{20,}$/',
            'env_var' => 'GEMINI_API_KEY',
        ],
        'groq' => [
            'name' => 'Groq',
            'key_prefix' => 'gsk_',
            'key_pattern' => '/^gsk_[a-zA-Z0-9_-]{20,}$/',
            'env_var' => 'GROQ_API_KEY',
        ],
        'deepseek' => [
            'name' => 'DeepSeek',
            'key_prefix' => 'sk-',
            'key_pattern' => '/^sk-[a-zA-Z0-9_-]{20,}$/',
            'env_var' => 'DEEPSEEK_API_KEY',
        ],
        'xai' => [
            'name' => 'xAI (Grok)',
            'key_prefix' => 'xai-',
            'key_pattern' => '/^xai-[a-zA-Z0-9_-]{20,}$/',
            'env_var' => 'XAI_API_KEY',
        ],
        'openrouter' => [
            'name' => 'OpenRouter',
            'key_prefix' => 'sk-or-',
            'key_pattern' => '/^sk-or-[a-zA-Z0-9_-]{20,}$/',
            'env_var' => 'OPENROUTER_API_KEY',
        ],
        'mistral' => [
            'name' => 'Mistral',
            'key_prefix' => 'sk-',
            'key_pattern' => '/^sk-[a-zA-Z0-9_-]{20,}$/',
            'env_var' => 'MISTRAL_API_KEY',
        ],
        'ollama' => [
            'name' => 'Ollama (Local)',
            'key_prefix' => null,
            'key_pattern' => null,
            'env_var' => null,
        ],
    ];

    public function providers(): array
    {
        return self::PROVIDERS;
    }

    public function providerName(string $provider): string
    {
        return $this->provider($provider)['name'];
    }

    public function requiresKey(string $provider): bool
    {
        return $this->provider($provider)['key_pattern'] !== null;
    }

    public function validate(string $provider, string $key): bool
    {
        $config = $this->provider($provider);

        if ($config['key_pattern'] === null) {
            return true;
        }

        return (bool) preg_match($config['key_pattern'], trim($key));
    }

    public function hasProvider(string $provider): bool
    {
        return array_key_exists($provider, self::PROVIDERS);
    }

    private function provider(string $provider): array
    {
        if (! $this->hasProvider($provider)) {
            throw new InvalidArgumentException("Unsupported provider [{$provider}].");
        }

        return self::PROVIDERS[$provider];
    }
}
