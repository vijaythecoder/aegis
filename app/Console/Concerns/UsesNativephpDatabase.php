<?php

namespace App\Console\Concerns;

use App\Models\Setting;
use App\Security\ApiKeyManager;
use App\Security\ProviderConfig;
use Illuminate\Support\Facades\DB;

/**
 * Bootstraps the NativePHP database connection for CLI commands.
 *
 * NativePHP stores all user data (API keys, settings, conversations) in a
 * separate SQLite database. CLI commands run outside NativePHP's runtime,
 * so they default to the wrong database. This trait mirrors the connection
 * setup from NativeServiceProvider and re-syncs API keys into Prism config.
 */
trait UsesNativephpDatabase
{
    protected function useNativephpDatabase(): bool
    {
        if (config('nativephp-internal.running')) {
            $this->syncApiKeysFromDatabase();

            return true;
        }

        $databasePath = database_path('nativephp.sqlite');

        if (! file_exists($databasePath)) {
            return false;
        }

        config(['database.connections.nativephp' => [
            'driver' => 'sqlite',
            'database' => $databasePath,
            'foreign_key_constraints' => true,
        ]]);

        config(['database.default' => 'nativephp']);

        DB::purge('nativephp');

        $this->syncApiKeysFromDatabase();

        return true;
    }

    private function syncApiKeysFromDatabase(): void
    {
        try {
            $apiKeyManager = app(ApiKeyManager::class);
            $providerConfig = app(ProviderConfig::class);

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
                $defaultProvider = Setting::query()
                    ->where('group', $group)
                    ->where('key', 'default_provider')
                    ->value('value');

                if (is_string($defaultProvider) && $defaultProvider !== '') {
                    config(['aegis.agent.default_provider' => $defaultProvider]);
                    break;
                }
            }

            foreach (['app', 'agent'] as $group) {
                $defaultModel = Setting::query()
                    ->where('group', $group)
                    ->where('key', 'default_model')
                    ->value('value');

                if (is_string($defaultModel) && $defaultModel !== '') {
                    config(['aegis.agent.default_model' => $defaultModel]);
                    break;
                }
            }
        } catch (\Throwable) {
        }
    }
}
