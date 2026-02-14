<?php

namespace App\Livewire;

use App\Agent\ProviderManager;
use App\Marketplace\MarketplaceService;
use App\Marketplace\PluginRegistry;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Memory;
use App\Models\Message;
use App\Models\Setting;
use App\Models\ToolPermission;
use App\Plugins\PluginInstaller;
use App\Plugins\PluginManager;
use App\Security\ApiKeyManager;
use App\Security\ProviderConfig;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Component;

class Settings extends Component
{
    public string $activeTab = 'providers';

    public string $apiKeyInput = '';

    public string $defaultProvider = '';

    public string $defaultModel = '';

    public string $flashMessage = '';

    public string $flashType = 'success';

    public string $marketplaceQuery = '';

    public function mount(): void
    {
        $this->defaultProvider = $this->getSettingValue('agent', 'default_provider')
            ?? config('aegis.agent.default_provider', 'anthropic');

        $this->defaultModel = $this->getSettingValue('agent', 'default_model')
            ?? config('aegis.agent.default_model', 'claude-sonnet-4-20250514');
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->flashMessage = '';
    }

    public function saveApiKey(string $provider): void
    {
        try {
            app(ApiKeyManager::class)->store($provider, $this->apiKeyInput);
            $this->apiKeyInput = '';
            $this->flash('API key saved successfully.', 'success');
        } catch (InvalidArgumentException) {
            $this->flash('Invalid API key format.', 'error');
        }
    }

    public function deleteApiKey(string $provider): void
    {
        app(ApiKeyManager::class)->delete($provider);
        $this->flash('API key removed.', 'success');
    }

    public function testConnection(string $provider): void
    {
        $key = $this->apiKeyInput ?: app(ApiKeyManager::class)->retrieve($provider);

        if (! $key) {
            $this->flash('No API key to test.', 'error');

            return;
        }

        $config = app(ProviderConfig::class);

        if (! $config->requiresKey($provider)) {
            $this->flash('Provider does not require a key — connection is valid.', 'success');

            return;
        }

        if ($config->validate($provider, $key)) {
            $this->flash('Key format is valid.', 'success');
        } else {
            $this->flash('Invalid key format.', 'error');
        }
    }

    public function saveDefaults(): void
    {
        $this->saveSetting('agent', 'default_provider', $this->defaultProvider);
        $this->saveSetting('agent', 'default_model', $this->defaultModel);
        $this->flash('Default provider and model saved.', 'success');
    }

    public function refreshMarketplace(): void
    {
        if (! config('aegis.marketplace.enabled', true)) {
            $this->flash('Marketplace is disabled.', 'error');

            return;
        }

        try {
            app(PluginRegistry::class)->sync(true);
            $this->flash('Marketplace plugins refreshed.', 'success');
        } catch (InvalidArgumentException $exception) {
            $this->flash($exception->getMessage(), 'error');
        }
    }

    public function installMarketplacePlugin(string $name): void
    {
        try {
            app(MarketplaceService::class)->install($name);
            $this->flash("Installed plugin [{$name}] from marketplace.", 'success');
        } catch (InvalidArgumentException $exception) {
            $this->flash($exception->getMessage(), 'error');
        }
    }

    public function removeMarketplacePlugin(string $name): void
    {
        if (! app(PluginInstaller::class)->remove($name)) {
            $this->flash("Plugin [{$name}] is not installed.", 'error');

            return;
        }

        $this->flash("Removed plugin [{$name}].", 'success');
    }

    public function updateMarketplacePlugin(string $name): void
    {
        $this->installMarketplacePlugin($name);
    }

    public function deletePermission(int $id): void
    {
        ToolPermission::query()->where('id', $id)->delete();
        $this->flash('Permission removed.', 'success');
    }

    public function clearMemories(): void
    {
        Memory::query()->delete();
        $this->flash('All memories cleared.', 'success');
    }

    public function clearAllData(): void
    {
        Message::query()->delete();
        Memory::query()->delete();
        Conversation::query()->delete();
        $this->flash('All data cleared.', 'success');
    }

    public function render()
    {
        $marketplacePlugins = collect();
        $marketplaceUpdates = [];

        if ($this->activeTab === 'marketplace' && config('aegis.marketplace.enabled', true)) {
            try {
                $service = app(MarketplaceService::class);
                $marketplacePlugins = $service->search($this->marketplaceQuery);
                $marketplaceUpdates = collect($service->checkUpdates())->keyBy('name')->all();
            } catch (InvalidArgumentException) {
            }
        }

        return view('livewire.settings', [
            'providers' => app(ApiKeyManager::class)->list(),
            'toolPermissions' => ToolPermission::query()->get(),
            'allowedPaths' => config('aegis.security.allowed_paths', []),
            'blockedCommands' => config('aegis.security.blocked_commands', []),
            'auditLogs' => AuditLog::query()->latest()->limit(50)->get(),
            'configuredProviders' => $this->getConfiguredProviders(),
            'providerStatus' => app(ProviderManager::class)->availableProviders(),
            'marketplaceEnabled' => config('aegis.marketplace.enabled', true),
            'marketplacePlugins' => $marketplacePlugins,
            'installedPlugins' => $this->installedPlugins(),
            'marketplaceUpdates' => $marketplaceUpdates,
        ]);
    }

    private function flash(string $message, string $type): void
    {
        $this->flashMessage = $message;
        $this->flashType = $type;
    }

    private function getSettingValue(string $group, string $key): ?string
    {
        return Setting::query()
            ->where('group', $group)
            ->where('key', $key)
            ->value('value');
    }

    private function saveSetting(string $group, string $key, string $value): void
    {
        Setting::query()->updateOrCreate(
            ['group' => $group, 'key' => $key],
            ['value' => $value, 'is_encrypted' => false],
        );
    }

    private function getConfiguredProviders(): array
    {
        $list = app(ApiKeyManager::class)->list();
        $configured = [];

        foreach ($list as $id => $info) {
            if ($info['is_set'] || ! $info['requires_key']) {
                $configured[$id] = $info['name'];
            }
        }

        return $configured;
    }

    private function installedPlugins(): Collection
    {
        return collect(app(PluginManager::class)->installed())->values();
    }
}
