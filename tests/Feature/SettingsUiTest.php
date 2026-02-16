<?php

use App\Livewire\Settings;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Memory;
use App\Models\Message;
use App\Models\ProactiveTask;
use App\Models\Setting;
use App\Models\ToolPermission;
use App\Security\ApiKeyManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// ── Route & Page ──────────────────────────────────────────────

it('has a settings route', function () {
    $response = test()->get('/settings');

    $response->assertStatus(200);
});

it('renders the settings page with livewire component', function () {
    $response = test()->get('/settings');

    $response->assertStatus(200);
    $response->assertSeeLivewire(Settings::class);
});

it('displays the page title as Settings', function () {
    $response = test()->get('/settings');

    $response->assertSee('Settings');
});

// ── Tab Navigation ────────────────────────────────────────────

it('defaults to providers tab', function () {
    Livewire::test(Settings::class)
        ->assertSet('activeTab', 'providers')
        ->assertSee('API Providers');
});

it('switches to security tab', function () {
    Livewire::test(Settings::class)
        ->call('setTab', 'security')
        ->assertSet('activeTab', 'security')
        ->assertSee('Tool Permissions');
});

it('switches to general tab', function () {
    Livewire::test(Settings::class)
        ->call('setTab', 'general')
        ->assertSet('activeTab', 'general')
        ->assertSee('Theme');
});

// ── Providers Tab ─────────────────────────────────────────────

it('lists all configured providers', function () {
    Livewire::test(Settings::class)
        ->assertSee('Anthropic')
        ->assertSee('OpenAI')
        ->assertSee('Google')
        ->assertSee('Ollama')
        ->assertSee('Groq')
        ->assertSee('DeepSeek')
        ->assertSee('OpenRouter')
        ->assertSee('xAI')
        ->assertSee('Mistral');
});

it('shows configured status for providers with keys', function () {
    $manager = app(ApiKeyManager::class);
    $manager->store('anthropic', 'sk-ant-ABCDEFGHIJKLMNOPQRSTUVWXYZ1234');

    Livewire::test(Settings::class)
        ->assertSee('Configured')
        ->assertSee('sk-...1234');
});

it('shows not configured status for providers without keys', function () {
    Livewire::test(Settings::class)
        ->assertSee('Not configured');
});

it('stores an api key for a provider', function () {
    Livewire::test(Settings::class)
        ->set('apiKeyInput', 'sk-ant-ABCDEFGHIJKLMNOPQRSTUVWXYZ1234')
        ->call('saveApiKey', 'anthropic')
        ->assertSee('Configured');

    $manager = app(ApiKeyManager::class);
    expect($manager->has('anthropic'))->toBeTrue();
});

it('shows error for invalid api key', function () {
    Livewire::test(Settings::class)
        ->set('apiKeyInput', 'bad-key')
        ->call('saveApiKey', 'anthropic')
        ->assertSee('Invalid');
});

it('deletes an api key', function () {
    $manager = app(ApiKeyManager::class);
    $manager->store('openai', 'sk-ABCDEFGHIJKLMNOPQRSTUVWXYZ1234');

    Livewire::test(Settings::class)
        ->call('deleteApiKey', 'openai');

    expect($manager->has('openai'))->toBeFalse();
});

it('tests connection for a valid key format', function () {
    Livewire::test(Settings::class)
        ->set('apiKeyInput', 'sk-ant-ABCDEFGHIJKLMNOPQRSTUVWXYZ1234')
        ->call('testConnection', 'anthropic')
        ->assertSee('valid');
});

it('saves default provider and model', function () {
    $manager = app(ApiKeyManager::class);
    $manager->store('anthropic', 'sk-ant-ABCDEFGHIJKLMNOPQRSTUVWXYZ1234');

    Livewire::test(Settings::class)
        ->set('defaultProvider', 'anthropic')
        ->set('defaultModel', 'claude-sonnet-4-20250514')
        ->call('saveDefaults');

    $provider = Setting::query()
        ->where('group', 'agent')
        ->where('key', 'default_provider')
        ->first();

    $model = Setting::query()
        ->where('group', 'agent')
        ->where('key', 'default_model')
        ->first();

    expect($provider->value)->toBe('anthropic')
        ->and($model->value)->toBe('claude-sonnet-4-20250514');
});

// ── Security Tab ──────────────────────────────────────────────

it('displays tool permissions', function () {
    ToolPermission::factory()->create([
        'tool_name' => 'shell_exec',
        'permission' => 'allow',
        'scope' => 'global',
    ]);

    Livewire::test(Settings::class)
        ->call('setTab', 'security')
        ->assertSee('shell_exec');
});

it('deletes a tool permission', function () {
    $perm = ToolPermission::factory()->create([
        'tool_name' => 'file_write',
        'permission' => 'allow',
        'scope' => 'global',
    ]);

    Livewire::test(Settings::class)
        ->call('setTab', 'security')
        ->call('deletePermission', $perm->id);

    expect(ToolPermission::find($perm->id))->toBeNull();
});

it('displays allowed directories from config', function () {
    Livewire::test(Settings::class)
        ->call('setTab', 'security')
        ->assertSee('Allowed Directories');
});

it('displays blocked commands from config', function () {
    Livewire::test(Settings::class)
        ->call('setTab', 'security')
        ->assertSee('Blocked Commands')
        ->assertSee('rm -rf /');
});

it('displays audit log entries', function () {
    AuditLog::factory()->create([
        'action' => 'tool.execute',
        'tool_name' => 'shell_exec',
        'result' => 'allowed',
    ]);

    Livewire::test(Settings::class)
        ->call('setTab', 'security')
        ->assertSee('tool.execute')
        ->assertSee('shell_exec');
});

// ── General Tab ───────────────────────────────────────────────

it('shows current theme as dark', function () {
    Livewire::test(Settings::class)
        ->call('setTab', 'general')
        ->assertSee('Dark');
});

it('clears all memories with confirmation', function () {
    Memory::factory()->count(3)->create();

    expect(Memory::count())->toBe(3);

    Livewire::test(Settings::class)
        ->call('setTab', 'general')
        ->call('clearMemories');

    expect(Memory::count())->toBe(0);
});

it('clears all data with confirmation', function () {
    $conv = Conversation::factory()->create();
    Message::factory()->create(['conversation_id' => $conv->id]);
    Memory::factory()->create(['conversation_id' => $conv->id]);

    Livewire::test(Settings::class)
        ->call('setTab', 'general')
        ->call('clearAllData');

    expect(Conversation::count())->toBe(0)
        ->and(Message::count())->toBe(0)
        ->and(Memory::count())->toBe(0);
});

it('has export data button that is disabled', function () {
    Livewire::test(Settings::class)
        ->call('setTab', 'general')
        ->assertSee('Coming soon');
});

// ── Memory & Embeddings Tab ──────────────────────────────────

it('switches to memory tab', function () {
    Livewire::test(Settings::class)
        ->call('setTab', 'memory')
        ->assertSet('activeTab', 'memory')
        ->assertSee('Embedding Provider');
});

it('shows embedding provider dropdown', function () {
    Livewire::test(Settings::class)
        ->call('setTab', 'memory')
        ->assertSee('Embedding Provider')
        ->assertSee('Ollama (Local)')
        ->assertSee('OpenAI (Cloud)')
        ->assertSee('Disabled');
});

it('saves embedding settings', function () {
    Livewire::test(Settings::class)
        ->call('setTab', 'memory')
        ->set('embeddingProvider', 'openai')
        ->set('embeddingModel', 'text-embedding-3-small')
        ->set('embeddingDimensions', 1536)
        ->call('saveEmbeddingSettings')
        ->assertSet('flashMessage', 'Embedding settings saved.');

    expect(Setting::query()->where('group', 'memory')->where('key', 'embedding_provider')->value('value'))
        ->toBe('openai');
    expect(Setting::query()->where('group', 'memory')->where('key', 'embedding_model')->value('value'))
        ->toBe('text-embedding-3-small');
    expect(Setting::query()->where('group', 'memory')->where('key', 'embedding_dimensions')->value('value'))
        ->toBe('1536');
});

it('shows disabled info when embeddings are disabled', function () {
    Livewire::test(Settings::class)
        ->call('setTab', 'memory')
        ->set('embeddingProvider', 'disabled')
        ->assertSee('keyword matching only');
});

it('shows ollama instructions when ollama is selected', function () {
    Livewire::test(Settings::class)
        ->call('setTab', 'memory')
        ->set('embeddingProvider', 'ollama')
        ->assertSee('ollama pull');
});

it('loads saved embedding settings on mount', function () {
    Setting::query()->create(['group' => 'memory', 'key' => 'embedding_provider', 'value' => 'openai', 'is_encrypted' => false]);
    Setting::query()->create(['group' => 'memory', 'key' => 'embedding_model', 'value' => 'text-embedding-3-small', 'is_encrypted' => false]);
    Setting::query()->create(['group' => 'memory', 'key' => 'embedding_dimensions', 'value' => '1536', 'is_encrypted' => false]);

    Livewire::test(Settings::class)
        ->assertSet('embeddingProvider', 'openai')
        ->assertSet('embeddingModel', 'text-embedding-3-small')
        ->assertSet('embeddingDimensions', 1536);
});

// ── Automation Tab ───────────────────────────────────────────

it('switches to automation tab', function () {
    Livewire::test(Settings::class)
        ->call('setTab', 'automation')
        ->assertSet('activeTab', 'automation')
        ->assertSee('Proactive Tasks');
});

it('lists proactive tasks on automation tab', function () {
    ProactiveTask::factory()->create(['name' => 'Test Briefing']);

    Livewire::test(Settings::class)
        ->call('setTab', 'automation')
        ->assertSee('Test Briefing');
});

it('toggles a proactive task active state', function () {
    $task = ProactiveTask::factory()->create(['is_active' => false]);

    Livewire::test(Settings::class)
        ->call('setTab', 'automation')
        ->call('toggleTask', $task->id);

    expect($task->fresh()->is_active)->toBeTrue();
});

it('creates a new proactive task', function () {
    Livewire::test(Settings::class)
        ->call('setTab', 'automation')
        ->call('newTask')
        ->set('taskName', 'Weekly Report')
        ->set('taskFrequency', 'weekly')
        ->set('taskTime', '09:00')
        ->set('taskDayOfWeek', '1')
        ->set('taskPrompt', 'Generate a weekly report.')
        ->set('taskDeliveryChannel', 'chat')
        ->call('saveTask')
        ->assertSet('flashMessage', 'Task "Weekly Report" created.');

    $task = ProactiveTask::query()->where('name', 'Weekly Report')->first();
    expect($task)->not->toBeNull()
        ->and($task->schedule)->toBe('0 9 * * 1');
});

it('edits an existing proactive task', function () {
    $task = ProactiveTask::factory()->create([
        'name' => 'Old Name',
        'schedule' => '0 8 * * *',
    ]);

    Livewire::test(Settings::class)
        ->call('setTab', 'automation')
        ->call('editTask', $task->id)
        ->assertSet('taskName', 'Old Name')
        ->set('taskName', 'New Name')
        ->call('saveTask')
        ->assertSet('flashMessage', 'Task "New Name" updated.');

    expect($task->fresh()->name)->toBe('New Name');
});

it('deletes a proactive task', function () {
    $task = ProactiveTask::factory()->create(['name' => 'Delete Me']);

    Livewire::test(Settings::class)
        ->call('setTab', 'automation')
        ->call('deleteTask', $task->id)
        ->assertSet('flashMessage', 'Task "Delete Me" deleted.');

    expect(ProactiveTask::find($task->id))->toBeNull();
});

it('validates required fields when creating task', function () {
    Livewire::test(Settings::class)
        ->call('setTab', 'automation')
        ->call('newTask')
        ->set('taskName', '')
        ->set('taskPrompt', '')
        ->call('saveTask')
        ->assertHasErrors(['taskName', 'taskPrompt']);
});

it('validates custom cron schedule is required', function () {
    Livewire::test(Settings::class)
        ->call('setTab', 'automation')
        ->call('newTask')
        ->set('taskName', 'Test')
        ->set('taskFrequency', 'custom')
        ->set('taskSchedule', '')
        ->set('taskPrompt', 'Do something')
        ->call('saveTask')
        ->assertHasErrors(['taskSchedule']);
});

it('generates correct cron for daily frequency', function () {
    Livewire::test(Settings::class)
        ->call('setTab', 'automation')
        ->call('newTask')
        ->set('taskName', 'Daily Task')
        ->set('taskFrequency', 'daily')
        ->set('taskTime', '10:30')
        ->set('taskPrompt', 'Run daily.')
        ->call('saveTask');

    expect(ProactiveTask::query()->where('name', 'Daily Task')->first()->schedule)->toBe('30 10 * * *');
});

it('generates correct cron for weekly frequency', function () {
    Livewire::test(Settings::class)
        ->call('setTab', 'automation')
        ->call('newTask')
        ->set('taskName', 'Weekday Task')
        ->set('taskFrequency', 'weekly')
        ->set('taskTime', '08:00')
        ->set('taskDayOfWeek', '1-5')
        ->set('taskPrompt', 'Weekday briefing.')
        ->call('saveTask');

    expect(ProactiveTask::query()->where('name', 'Weekday Task')->first()->schedule)->toBe('0 8 * * 1-5');
});

it('generates correct cron for monthly frequency', function () {
    Livewire::test(Settings::class)
        ->call('setTab', 'automation')
        ->call('newTask')
        ->set('taskName', 'Monthly Task')
        ->set('taskFrequency', 'monthly')
        ->set('taskTime', '14:00')
        ->set('taskDayOfMonth', '15')
        ->set('taskPrompt', 'Monthly report.')
        ->call('saveTask');

    expect(ProactiveTask::query()->where('name', 'Monthly Task')->first()->schedule)->toBe('0 14 15 * *');
});

it('generates correct cron for hourly frequency', function () {
    Livewire::test(Settings::class)
        ->call('setTab', 'automation')
        ->call('newTask')
        ->set('taskName', 'Hourly Check')
        ->set('taskFrequency', 'hourly')
        ->set('taskPrompt', 'Check status.')
        ->call('saveTask');

    expect(ProactiveTask::query()->where('name', 'Hourly Check')->first()->schedule)->toBe('0 * * * *');
});

it('passes through custom cron expression', function () {
    Livewire::test(Settings::class)
        ->call('setTab', 'automation')
        ->call('newTask')
        ->set('taskName', 'Custom Task')
        ->set('taskFrequency', 'custom')
        ->set('taskSchedule', '*/15 * * * *')
        ->set('taskPrompt', 'Every 15 minutes.')
        ->call('saveTask');

    expect(ProactiveTask::query()->where('name', 'Custom Task')->first()->schedule)->toBe('*/15 * * * *');
});

it('parses existing cron back into picker fields on edit', function () {
    $task = ProactiveTask::factory()->create([
        'schedule' => '0 8 * * 1-5',
    ]);

    Livewire::test(Settings::class)
        ->call('setTab', 'automation')
        ->call('editTask', $task->id)
        ->assertSet('taskFrequency', 'weekly')
        ->assertSet('taskTime', '08:00')
        ->assertSet('taskDayOfWeek', '1-5');
});

it('displays human-readable schedule in task list', function () {
    ProactiveTask::factory()->create([
        'name' => 'Briefing',
        'schedule' => '0 8 * * 1-5',
    ]);

    Livewire::test(Settings::class)
        ->call('setTab', 'automation')
        ->assertSee('Weekdays at 8:00 AM');
});

it('converts common cron patterns to human text', function () {
    expect(Settings::humanReadableSchedule('0 8 * * 1-5'))->toBe('Weekdays at 8:00 AM')
        ->and(Settings::humanReadableSchedule('0 18 * * 0'))->toBe('Sundays at 6:00 PM')
        ->and(Settings::humanReadableSchedule('0 10 * * *'))->toBe('Daily at 10:00 AM')
        ->and(Settings::humanReadableSchedule('0 9 * * 1'))->toBe('Mondays at 9:00 AM')
        ->and(Settings::humanReadableSchedule('30 14 15 * *'))->toBe('Monthly on the 15th at 2:30 PM')
        ->and(Settings::humanReadableSchedule('0 * * * *'))->toBe('Every hour');
});
