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
use Livewire\Attributes\Url;
use Livewire\Component;
use Native\Laravel\Facades\ChildProcess;
use Throwable;

class Settings extends Component
{
    #[Url(as: 'tab')]
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

    public bool $showTaskForm = false;

    public ?int $editingTaskId = null;

    public string $taskName = '';

    public string $taskFrequency = 'daily';

    public string $taskTime = '08:00';

    public string $taskDayOfWeek = '1';

    public string $taskDayOfMonth = '1';

    public string $taskSchedule = '';

    public string $taskPrompt = '';

    public string $taskDeliveryChannel = 'chat';

    public bool $imessageEnabled = false;

    public bool $telegramEnabled = false;

    public string $imessageChatId = '';

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

        $this->imessageEnabled = (bool) ($this->getSettingValue('messaging', 'imessage_enabled')
            ?? config('aegis.messaging.imessage.enabled', PHP_OS === 'Darwin'));

        $this->telegramEnabled = (bool) $this->getSettingValue('messaging', 'telegram_enabled');

        if ($this->telegramEnabled === false && config('aegis.messaging.telegram.bot_token')) {
            $this->telegramEnabled = true;
        }

        $this->imessageChatId = $this->getSettingValue('messaging', 'imessage_chat_id') ?? '';
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
        $this->flashMessage = '';
    }

    public function toggleIMessage(): void
    {
        if (PHP_OS !== 'Darwin') {
            $this->flash('iMessage is only available on macOS.', 'error');

            return;
        }

        $this->imessageEnabled = ! $this->imessageEnabled;
        $this->saveSetting('messaging', 'imessage_enabled', $this->imessageEnabled ? '1' : '0');
        config(['aegis.messaging.imessage.enabled' => $this->imessageEnabled]);

        try {
            if ($this->imessageEnabled) {
                ChildProcess::artisan(['aegis:imessage:poll'], 'imessage-poller', persistent: true);
            } else {
                ChildProcess::stop('imessage-poller');
            }
        } catch (Throwable $e) {
            \Illuminate\Support\Facades\Log::debug('iMessage process control failed', ['error' => $e->getMessage()]);
        }

        $status = $this->imessageEnabled ? 'enabled' : 'disabled';
        $this->flash("iMessage integration {$status}.", 'success');
    }

    public function toggleTelegram(): void
    {
        $token = (string) config('aegis.messaging.telegram.bot_token', '');

        if ($token === '') {
            $this->flash('Set your Telegram bot token in the Providers tab first.', 'error');

            return;
        }

        $this->telegramEnabled = ! $this->telegramEnabled;
        $this->saveSetting('messaging', 'telegram_enabled', $this->telegramEnabled ? '1' : '0');

        try {
            if ($this->telegramEnabled) {
                ChildProcess::artisan(['telegram:poll'], 'telegram-poller', persistent: true);
            } else {
                ChildProcess::stop('telegram-poller');
            }
        } catch (Throwable $e) {
            \Illuminate\Support\Facades\Log::debug('Telegram process control failed', ['error' => $e->getMessage()]);
        }

        $status = $this->telegramEnabled ? 'enabled' : 'disabled';
        $this->flash("Telegram integration {$status}.", 'success');
    }

    public function saveIMessageChatId(): void
    {
        $raw = trim($this->imessageChatId);
        $this->saveSetting('messaging', 'imessage_chat_id', $raw);

        $contacts = array_filter(array_map('trim', explode(',', $raw)), fn (string $c): bool => $c !== '');

        if ($contacts === []) {
            $this->flash('iMessage contact list cleared. The agent will not respond until contacts are set.', 'success');
        } else {
            $count = count($contacts);
            $this->flash("iMessage agent will respond to {$count} contact".($count !== 1 ? 's' : '').'.', 'success');
        }
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

        $this->showTaskForm = true;
        $this->editingTaskId = $task->id;
        $this->taskName = $task->name;
        $this->taskPrompt = $task->prompt;
        $this->taskDeliveryChannel = $task->delivery_channel;
        $this->parseCronToSchedule($task->schedule);
    }

    public function saveTask(): void
    {
        $rules = [
            'taskName' => 'required|string|max:255',
            'taskFrequency' => 'required|in:daily,weekly,monthly,hourly,custom',
            'taskPrompt' => 'required|string',
            'taskDeliveryChannel' => 'required|in:chat,telegram,notification',
        ];

        if ($this->taskFrequency === 'custom') {
            $rules['taskSchedule'] = 'required|string|max:100';
        } elseif (in_array($this->taskFrequency, ['daily', 'weekly', 'monthly'])) {
            $rules['taskTime'] = 'required|date_format:H:i';
        }

        if ($this->taskFrequency === 'weekly') {
            $rules['taskDayOfWeek'] = 'required|in:0,1,2,3,4,5,6,1-5';
        }

        if ($this->taskFrequency === 'monthly') {
            $rules['taskDayOfMonth'] = 'required|integer|between:1,28';
        }

        $this->validate($rules);

        $schedule = $this->buildCronFromSchedule();

        if ($this->editingTaskId !== null) {
            $task = ProactiveTask::query()->findOrFail($this->editingTaskId);
            $task->update([
                'name' => $this->taskName,
                'schedule' => $schedule,
                'prompt' => $this->taskPrompt,
                'delivery_channel' => $this->taskDeliveryChannel,
            ]);
            $this->flash("Task \"{$this->taskName}\" updated.", 'success');
        } else {
            ProactiveTask::query()->create([
                'name' => $this->taskName,
                'schedule' => $schedule,
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
        $this->showTaskForm = true;
    }

    public function cancelTaskEdit(): void
    {
        $this->resetTaskForm();
    }

    private function resetTaskForm(): void
    {
        $this->showTaskForm = false;
        $this->editingTaskId = null;
        $this->taskName = '';
        $this->taskFrequency = 'daily';
        $this->taskTime = '08:00';
        $this->taskDayOfWeek = '1';
        $this->taskDayOfMonth = '1';
        $this->taskSchedule = '';
        $this->taskPrompt = '';
        $this->taskDeliveryChannel = 'chat';
    }

    private function buildCronFromSchedule(): string
    {
        if ($this->taskFrequency === 'custom') {
            return $this->taskSchedule;
        }

        [$hour, $minute] = array_map('intval', explode(':', $this->taskTime));

        return match ($this->taskFrequency) {
            'hourly' => "{$minute} * * * *",
            'daily' => "{$minute} {$hour} * * *",
            'weekly' => "{$minute} {$hour} * * {$this->taskDayOfWeek}",
            'monthly' => "{$minute} {$hour} {$this->taskDayOfMonth} * *",
            default => "{$minute} {$hour} * * *",
        };
    }

    private function parseCronToSchedule(string $cron): void
    {
        $parts = preg_split('/\s+/', trim($cron));

        if (count($parts) !== 5) {
            $this->taskFrequency = 'custom';
            $this->taskSchedule = $cron;

            return;
        }

        [$minute, $hour, $dayOfMonth, $month, $dayOfWeek] = $parts;

        if ($month !== '*') {
            $this->taskFrequency = 'custom';
            $this->taskSchedule = $cron;

            return;
        }

        if ($hour === '*' && $dayOfMonth === '*' && $dayOfWeek === '*') {
            $this->taskFrequency = 'hourly';
            $this->taskTime = '00:'.str_pad($minute, 2, '0', STR_PAD_LEFT);

            return;
        }

        $this->taskTime = str_pad($hour, 2, '0', STR_PAD_LEFT).':'.str_pad($minute, 2, '0', STR_PAD_LEFT);

        if ($dayOfMonth !== '*' && $dayOfWeek === '*') {
            $this->taskFrequency = 'monthly';
            $this->taskDayOfMonth = $dayOfMonth;

            return;
        }

        if ($dayOfMonth === '*' && $dayOfWeek !== '*') {
            $this->taskFrequency = 'weekly';
            $this->taskDayOfWeek = $dayOfWeek;

            return;
        }

        if ($dayOfMonth === '*' && $dayOfWeek === '*') {
            $this->taskFrequency = 'daily';

            return;
        }

        $this->taskFrequency = 'custom';
        $this->taskSchedule = $cron;
    }

    public static function humanReadableSchedule(string $cron): string
    {
        $parts = preg_split('/\s+/', trim($cron));

        if (count($parts) !== 5) {
            return $cron;
        }

        [$minute, $hour, $dayOfMonth, $month, $dayOfWeek] = $parts;

        if ($month !== '*') {
            return $cron;
        }

        $dayNames = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];

        $formatTime = function (string $h, string $m): string {
            $hour = (int) $h;
            $min = str_pad($m, 2, '0', STR_PAD_LEFT);
            $period = $hour >= 12 ? 'PM' : 'AM';
            $displayHour = $hour % 12 ?: 12;

            return "{$displayHour}:{$min} {$period}";
        };

        if ($hour === '*' && $dayOfMonth === '*' && $dayOfWeek === '*') {
            $min = (int) $minute;

            return $min === 0 ? 'Every hour' : "Every hour at :{$minute}";
        }

        $time = $formatTime($hour, $minute);

        if ($dayOfMonth !== '*' && $dayOfWeek === '*') {
            $suffix = match ((int) $dayOfMonth) {
                1, 21, 31 => 'st',
                2, 22 => 'nd',
                3, 23 => 'rd',
                default => 'th',
            };

            return "Monthly on the {$dayOfMonth}{$suffix} at {$time}";
        }

        if ($dayOfMonth === '*' && $dayOfWeek !== '*') {
            if ($dayOfWeek === '*') {
                return "Daily at {$time}";
            }

            if ($dayOfWeek === '1-5') {
                return "Weekdays at {$time}";
            }

            if ($dayOfWeek === '0,6') {
                return "Weekends at {$time}";
            }

            $days = array_map(function ($d) use ($dayNames) {
                $idx = (int) $d;

                return $dayNames[$idx] ?? $d;
            }, explode(',', $dayOfWeek));

            if (count($days) === 1) {
                $fullDayNames = ['Sundays', 'Mondays', 'Tuesdays', 'Wednesdays', 'Thursdays', 'Fridays', 'Saturdays'];
                $idx = (int) $dayOfWeek;

                return ($fullDayNames[$idx] ?? $dayOfWeek)." at {$time}";
            }

            return implode(', ', $days)." at {$time}";
        }

        if ($dayOfMonth === '*' && $dayOfWeek === '*') {
            return "Daily at {$time}";
        }

        return $cron;
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
            'imessagePollerRunning' => $this->isPollerRunning('imessage-poller'),
            'telegramPollerRunning' => $this->isPollerRunning('telegram-poller'),
            'memories' => $this->activeTab === 'memory' ? app(MemoryService::class)->all() : collect(),
            'userProfile' => $this->activeTab === 'memory' ? app(UserProfileService::class)->getProfile() : null,
            'proactiveTasks' => $this->activeTab === 'automation' ? ProactiveTask::query()->orderBy('name')->get() : collect(),
        ]);
    }

    private function isPollerRunning(string $alias): bool
    {
        try {
            return ChildProcess::get($alias) !== null;
        } catch (Throwable) {
            return false;
        }
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
