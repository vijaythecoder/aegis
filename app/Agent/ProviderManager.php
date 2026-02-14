<?php

namespace App\Agent;

use App\Security\ApiKeyManager;
use App\Security\ProviderConfig;
use Closure;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class ProviderManager
{
    public function __construct(
        private readonly ApiKeyManager $apiKeyManager,
        private readonly ProviderConfig $providerConfig,
        private readonly ModelCapabilities $modelCapabilities,
    ) {}

    public function resolve(?string $provider = null, ?string $model = null): array
    {
        $configuredProvider = (string) config('aegis.agent.default_provider', 'anthropic');
        $resolvedProvider = $provider ?: $configuredProvider;

        if (! $this->providerConfig->hasProvider($resolvedProvider)) {
            $resolvedProvider = $configuredProvider;
        }

        $providerModels = $this->modelCapabilities->modelsForProvider($resolvedProvider);

        if ($resolvedProvider === 'ollama' && $providerModels === []) {
            $providerModels = $this->detectOllama();
        }

        if (is_string($model) && $model !== '') {
            if ($providerModels === [] || in_array($model, $providerModels, true)) {
                return [$resolvedProvider, $model];
            }
        }

        // Use global default model only if it belongs to the resolved provider's model list.
        // Different providers use different model ID formats (e.g., OpenRouter uses "anthropic/claude-sonnet-4-20250514").
        $configuredModel = (string) config('aegis.agent.default_model', 'claude-sonnet-4-20250514');
        if ($providerModels !== [] && in_array($configuredModel, $providerModels, true)) {
            return [$resolvedProvider, $configuredModel];
        }

        // Fall back to the provider's own default model.
        $defaultModel = $this->modelCapabilities->defaultModel($resolvedProvider);

        if ($defaultModel !== '') {
            return [$resolvedProvider, $defaultModel];
        }

        // Last resort: first model in the provider's list, or global default.
        if ($providerModels !== []) {
            return [$resolvedProvider, (string) $providerModels[0]];
        }

        return [$resolvedProvider, $configuredModel];
    }

    public function failover(string $primaryProvider, Closure $action): mixed
    {
        $providers = array_values(array_unique([
            $primaryProvider,
            ...config('aegis.failover_chain', []),
        ]));

        $lastException = null;

        foreach ($providers as $provider) {
            if (! is_string($provider) || $provider === '') {
                continue;
            }

            if ($this->isRateLimited($provider)) {
                continue;
            }

            try {
                return $action($provider);
            } catch (Throwable $exception) {
                $lastException = $exception;

                Log::warning('aegis.provider.failover', [
                    'failed_provider' => $provider,
                    'fallback_chain' => $providers,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        throw $lastException ?? new RuntimeException('No available providers in failover chain.');
    }

    public function detectOllama(): array
    {
        $baseUrl = (string) config('aegis.providers.ollama.base_url', 'http://localhost:11434');

        try {
            $response = Http::timeout(2)->get(rtrim($baseUrl, '/').'/api/tags');

            if (! $response->successful()) {
                return [];
            }

            $models = $response->json('models', []);

            return collect($models)
                ->map(fn (mixed $item): string => (string) ($item['name'] ?? ''))
                ->filter(fn (string $name): bool => $name !== '')
                ->values()
                ->all();
        } catch (Throwable) {
            return [];
        }
    }

    public function availableProviders(): array
    {
        $providers = [];

        foreach (array_keys(config('aegis.providers', [])) as $provider) {
            $providers[$provider] = [
                'available' => $this->isAvailable($provider),
                'rate_limited' => $this->isRateLimited($provider),
                'models' => $provider === 'ollama'
                    ? $this->detectOllama()
                    : $this->modelCapabilities->modelsForProvider($provider),
            ];
        }

        return $providers;
    }

    public function isAvailable(string $provider): bool
    {
        if (! $this->providerConfig->hasProvider($provider)) {
            return false;
        }

        if (! $this->providerConfig->requiresKey($provider)) {
            return true;
        }

        return $this->apiKeyManager->has($provider);
    }

    public function trackRateLimit(string $provider): void
    {
        $window = max(1, (int) config('aegis.agent.rate_limit_window_seconds', 60));
        $key = $this->rateLimitKey($provider);

        if (! Cache::has($key)) {
            Cache::put($key, 0, $window);
        }

        Cache::increment($key);
        Cache::put($key, (int) Cache::get($key, 0), $window);
    }

    public function isRateLimited(string $provider): bool
    {
        $max = max(1, (int) config('aegis.agent.rate_limit_max_requests', 120));

        return (int) Cache::get($this->rateLimitKey($provider), 0) >= $max;
    }

    private function rateLimitKey(string $provider): string
    {
        return 'aegis:provider-rate-limit:'.$provider;
    }
}
