<?php

namespace App\Providers;

use App\Agent\AegisAgent;
use App\Agent\AegisConversationStore;
use App\Agent\AgentRegistry;
use App\Desktop\Contracts\DesktopBridge;
use App\Desktop\ElectronDesktopBridge;
use App\Messaging\Adapters\DiscordAdapter;
use App\Messaging\Adapters\SlackAdapter;
use App\Messaging\Adapters\TelegramAdapter;
use App\Messaging\Adapters\WhatsAppAdapter;
use App\Messaging\Contracts\MessagingAdapter;
use App\Messaging\MessageRouter;
use App\Messaging\SessionBridge;
use App\Plugins\PluginInstaller;
use App\Plugins\PluginManager;
use App\Tools\ToolRegistry;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Ai\Contracts\ConversationStore;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(ConversationStore::class, AegisConversationStore::class);
        $this->app->singleton(DesktopBridge::class, ElectronDesktopBridge::class);
        $this->app->singleton(AegisAgent::class);
        $this->app->singleton(AgentRegistry::class);
        $this->app->singleton(SessionBridge::class);
        $this->app->singleton(ToolRegistry::class);
        $this->app->bind(PluginManager::class, function ($app, array $parameters): PluginManager {
            $pluginsPath = (string) ($parameters['pluginsPath'] ?? config('aegis.plugins.path', base_path('plugins')));

            return new PluginManager($app, $pluginsPath);
        });
        $this->app->singleton(PluginInstaller::class);
        $this->app->singleton(MessageRouter::class, function ($app): MessageRouter {
            $router = new MessageRouter(
                sessionBridge: $app->make(SessionBridge::class),
                agent: $app->make(AegisAgent::class),
            );

            foreach ((array) config('aegis.messaging.adapters', []) as $platform => $adapterClass) {
                $adapter = $app->make($adapterClass);

                if ($adapter instanceof MessagingAdapter) {
                    $router->registerAdapter((string) $platform, $adapter);
                }
            }

            return $router;
        });
    }

    public function boot(): void
    {
        $this->syncApiKeysIntoPrismConfig();

        $this->app->afterResolving(MessageRouter::class, function (MessageRouter $router): void {
            $router->registerAdapter('discord', $this->app->make(DiscordAdapter::class));
            $router->registerAdapter('slack', $this->app->make(SlackAdapter::class));
            $router->registerAdapter('telegram', $this->app->make(TelegramAdapter::class));
            $router->registerAdapter('whatsapp', $this->app->make(WhatsAppAdapter::class));
        });

        if (config('aegis.plugins.enabled', true)) {
            $this->app->make(PluginManager::class)->bootAll();
        }

        Route::middleware('web')->group(base_path('routes/messaging.php'));
    }

    /**
     * Sync API keys stored in the database into Prism's runtime config.
     *
     * Aegis stores API keys encrypted in the settings table (via ApiKeyManager).
     * Prism reads from config('prism.providers.{provider}.api_key') which defaults
     * to env vars. This bridge injects the DB-stored keys so Prism can authenticate.
     * Also syncs the user's default provider/model from settings into aegis config.
     */
    private function syncApiKeysIntoPrismConfig(): void
    {
        try {
            $apiKeyManager = $this->app->make(\App\Security\ApiKeyManager::class);
            $providerConfig = $this->app->make(\App\Security\ProviderConfig::class);

            $prismProviderMap = [
                'anthropic' => 'anthropic',
                'openai' => 'openai',
                'gemini' => 'gemini',
                'groq' => 'groq',
                'deepseek' => 'deepseek',
                'xai' => 'xai',
                'openrouter' => 'openrouter',
                'mistral' => 'mistral',
            ];

            foreach ($prismProviderMap as $aegisProvider => $prismProvider) {
                if (! $providerConfig->requiresKey($aegisProvider)) {
                    continue;
                }

                $existingKey = config("prism.providers.{$prismProvider}.api_key", '');
                if (is_string($existingKey) && $existingKey !== '') {
                    continue;
                }

                $dbKey = $apiKeyManager->retrieve($aegisProvider);
                if ($dbKey !== null && $dbKey !== '') {
                    config(["prism.providers.{$prismProvider}.api_key" => $dbKey]);
                    config(["ai.providers.{$prismProvider}.key" => $dbKey]);
                }
            }

            foreach (['app', 'agent'] as $group) {
                $defaultProvider = \App\Models\Setting::query()
                    ->where('group', $group)
                    ->where('key', 'default_provider')
                    ->value('value');

                if (is_string($defaultProvider) && $defaultProvider !== '') {
                    config(['aegis.agent.default_provider' => $defaultProvider]);
                    break;
                }
            }

            foreach (['app', 'agent'] as $group) {
                $defaultModel = \App\Models\Setting::query()
                    ->where('group', $group)
                    ->where('key', 'default_model')
                    ->value('value');

                if (is_string($defaultModel) && $defaultModel !== '') {
                    config(['aegis.agent.default_model' => $defaultModel]);
                    break;
                }
            }
        } catch (\Throwable) {
            // Database may not be available yet (migrations pending, testing).
        }
    }
}
