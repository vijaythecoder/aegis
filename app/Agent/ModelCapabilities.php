<?php

namespace App\Agent;

use App\Services\OpenRouterModelService;

class ModelCapabilities
{
    public function contextWindow(string $provider, string $model): int
    {
        $value = $this->modelConfig($provider, $model)['context_window'] ?? null;

        if (is_numeric($value)) {
            return (int) $value;
        }

        return (int) config('aegis.agent.context_window', 8000);
    }

    public function supportsTools(string $provider, string $model): bool
    {
        return (bool) ($this->modelConfig($provider, $model)['tools'] ?? true);
    }

    public function supportsVision(string $provider, string $model): bool
    {
        return (bool) ($this->modelConfig($provider, $model)['vision'] ?? false);
    }

    public function supportsStreaming(string $provider, string $model): bool
    {
        return (bool) ($this->modelConfig($provider, $model)['streaming'] ?? true);
    }

    public function supportsStructuredOutput(string $provider, string $model): bool
    {
        return (bool) ($this->modelConfig($provider, $model)['structured_output'] ?? false);
    }

    public function modelsForProvider(string $provider): array
    {
        if ($provider === 'openrouter') {
            $dynamic = app(OpenRouterModelService::class)->getModelIds();

            if ($dynamic !== []) {
                return $dynamic;
            }
        }

        return array_keys(config("aegis.providers.{$provider}.models", []));
    }

    public function defaultModel(string $provider): string
    {
        $default = config("aegis.providers.{$provider}.default_model");

        if (is_string($default) && $default !== '') {
            return $default;
        }

        $models = $this->modelsForProvider($provider);

        return (string) ($models[0] ?? config('aegis.agent.default_model', ''));
    }

    private function modelConfig(string $provider, string $model): array
    {
        if ($provider === 'openrouter') {
            $details = app(OpenRouterModelService::class)->getModelDetails($model);

            if ($details !== null) {
                return [
                    'context_window' => $details['context_length'],
                    'tools' => $details['tools'],
                    'vision' => $details['vision'],
                    'streaming' => $details['streaming'],
                    'structured_output' => $details['structured_output'],
                ];
            }
        }

        return config("aegis.providers.{$provider}.models.{$model}", []);
    }
}
