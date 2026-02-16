<?php

namespace App\Livewire;

use App\Agent\ModelCapabilities;
use App\Agent\ProviderManager;
use App\Marketplace\MarketplaceService;
use App\Marketplace\PluginRegistry;
use App\Memory\MemoryService;
use App\Memory\UserProfileService;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Memory;
use App\Models\Message;
use App\Models\ProactiveTask;
use App\Models\Setting;
use App\Models\ToolPermission;
use App\Plugins\PluginInstaller;
use App\Plugins\PluginManager;
use App\Security\ApiKeyManager;
use App\Security\ProviderConfig;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use Livewire\Component;
use Throwable;

class Settings extends Component
{
    public string $activeTab = 'providers';

    public string $apiKeyInput = '';

    public string $defaultProvider = '';

    public string $defaultModel = '';

    public string $flashMessage = '';

    public string $flashType = 'success';

    public string $marketplaceQuery = '';

    public string $modelRole = 'default';

    public string $embeddingProvider = '';

    public string $embeddingModel = '';

    public int $embeddingDimensions = 768;

    public ?int $editingTaskId = null;

    public string $taskName = '';

    public string $taskSchedule = '';

    public string $taskPrompt = '';

    public string $taskDeliveryChannel = 'chat';

    public function mount(): void
    {
        $this->defaultProvider = $this->getSettingValue('agent', 'default_provider')
            ?? config('aegis.agent.default_provider', 'anthropic');

        $this->defaultModel = $this->getSettingValue('agent', 'default_model')
            ?? config('aegis.agent.default_model', 'claude-sonnet-4-20250514');

        $this->modelRole = $this->getSettingValue('agent', 'model_role') ?? 'default';

        $this->embeddingProvider = $this->getSettingValue('memory', 'embedding_provider')
            ?? config('aegis.memory.embedding_provider', 'ollama');

        $this->embeddingModel = $this->getSettingValue('memory', 'embedding_model')
            ?? config('aegis.memory.embedding_model', 'nomic-embed-text');

        $this->embeddingDimensions = (int) ($this->getSettingValue('memory', 'embedding_dimensions')
            ?? config('aegis.memory.embedding_dimensions', 768));
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

    public function updatedDefaultProvider(): void
    {
        $models = $this->availableModels();
        $this->defaultModel = $models[0] ?? '';
    }

    public function availableModels(): array
    {
        $capabilities = app(ModelCapabilities::class);
        $models = $capabilities->modelsForProvider($this->defaultProvider);

        if ($this->defaultProvider === 'ollama' && $models === []) {
            $models = app(ProviderManager::class)->detectOllama();
        }

        return $models;
    }

    public function saveDefaults(): void
    {
        $this->saveSetting('agent', 'default_provider', $this->defaultProvider);
        $this->saveSetting('agent', 'default_model', $this->defaultModel);
        $this->saveSetting('agent', 'model_role', $this->modelRole);
        $this->flash('Default provider, model, and role saved.', 'success');
    }

    public function saveEmbeddingSettings(): void
    {
        $this->saveSetting('memory', 'embedding_provider', $this->embeddingProvider);
        $this->saveSetting('memory', 'embedding_model', $this->embeddingModel);
        $this->saveSetting('memory', 'embedding_dimensions', (string) $this->embeddingDimensions);

        config([
            'aegis.memory.embedding_provider' => $this->embeddingProvider,
            'aegis.memory.embedding_model' => $this->embeddingModel,
            'aegis.memory.embedding_dimensions' => $this->embeddingDimensions,
        ]);

        $this->flash('Embedding settings saved.', 'success');
    }

    public function testEmbeddingConnection(): void
    {
        if ($this->embeddingProvider === 'disabled') {
            $this->flash('Embeddings are disabled.', 'error');

            return;
        }

        try {
            $service = app(\App\Memory\EmbeddingService::class);

            config([
                'aegis.memory.embedding_provider' => $this->embeddingProvider,
                'aegis.memory.embedding_model' => $this->embeddingModel,
                'aegis.memory.embedding_dimensions' => $this->embeddingDimensions,
            ]);

            $result = $service->embed('test connection');

            if ($result !== null) {
                $dims = count($result);
                $this->flash("Connection successful. Generated {$dims}-dimension embedding.", 'success');
            } else {
                $this->flash('Connection failed. Provider returned no embedding.', 'error');
            }
        } catch (Throwable $e) {
            $this->flash('Connection failed: '.$e->getMessage(), 'error');
        }
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

    public function deleteMemory(int $id): void
    {
        app(MemoryService::class)->delete($id);
        $this->flash('Memory deleted.', 'success');
    }

    public function refreshUserProfile(): void
    {
        $profile = app(UserProfileService::class)->refreshProfile();

        if ($profile !== null) {
            $this->flash('User profile refreshed.', 'success');
        } else {
            $this->flash('No memories available to build profile.', 'error');
        }
    }

    public function clearMemories(): void
    {
        Memory::query()->delete();
        app(UserProfileService::class)->invalidate();
        $this->flash('All memories cleared.', 'success');
    }

    public function clearAllData(): void
    {
        Message::query()->delete();
        Memory::query()->delete();
        Conversation::query()->delete();
        $this->flash('All data cleared.', 'success');
    }

    public function toggleTask(int $id): void
    {
        $task = ProactiveTask::query()->findOrFail($id);
        $task->update(['is_active' => ! $task->is_active]);

        $status = $task->is_active ? 'enabled' : 'disabled';
        $this->flash("Task \"{$task->name}\" {$status}.", 'success');
    }

    public function editTask(int $id): void
    {
        $task = ProactiveTask::query()->findOrFail($id);

        $this->editingTaskId = $task->id;
        $this->taskName = $task->name;
        $this->taskSchedule = $task->schedule;
        $this->taskPrompt = $task->prompt;
        $this->taskDeliveryChannel = $task->delivery_channel;
    }

    public function saveTask(): void
    {
        $this->validate([
            'taskName' => 'required|string|max:255',
            'taskSchedule' => 'required|string|max:100',
            'taskPrompt' => 'required|string',
            'taskDeliveryChannel' => 'required|in:chat,telegram,notification',
        ]);

        if ($this->editingTaskId !== null) {
            $task = ProactiveTask::query()->findOrFail($this->editingTaskId);
            $task->update([
                'name' => $this->taskName,
                'schedule' => $this->taskSchedule,
                'prompt' => $this->taskPrompt,
                'delivery_channel' => $this->taskDeliveryChannel,
            ]);
            $this->flash("Task \"{$this->taskName}\" updated.", 'success');
        } else {
            ProactiveTask::query()->create([
                'name' => $this->taskName,
                'schedule' => $this->taskSchedule,
                'prompt' => $this->taskPrompt,
                'delivery_channel' => $this->taskDeliveryChannel,
                'is_active' => false,
            ]);
            $this->flash("Task \"{$this->taskName}\" created.", 'success');
        }

        $this->resetTaskForm();
    }

    public function deleteTask(int $id): void
    {
        $task = ProactiveTask::query()->findOrFail($id);
        $name = $task->name;
        $task->delete();
        $this->flash("Task \"{$name}\" deleted.", 'success');
    }

    public function newTask(): void
    {
        $this->resetTaskForm();
        $this->editingTaskId = null;
    }

    public function cancelTaskEdit(): void
    {
        $this->resetTaskForm();
    }

    private function resetTaskForm(): void
    {
        $this->editingTaskId = null;
        $this->taskName = '';
        $this->taskSchedule = '';
        $this->taskPrompt = '';
        $this->taskDeliveryChannel = 'chat';
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
            } catch (Throwable) {
                // Marketplace unreachable — show cached data or empty
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
            'memories' => $this->activeTab === 'memory' ? app(MemoryService::class)->all() : collect(),
            'userProfile' => $this->activeTab === 'memory' ? app(UserProfileService::class)->getProfile() : null,
            'proactiveTasks' => $this->activeTab === 'automation' ? ProactiveTask::query()->orderBy('name')->get() : collect(),
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
