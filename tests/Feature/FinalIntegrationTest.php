<?php

use App\Agent\ProviderManager;
use App\Desktop\UpdateService;
use App\Marketplace\MarketplaceService;
use App\Marketplace\PluginRegistry;
use App\Messaging\Adapters\DiscordAdapter;
use App\Messaging\Adapters\IMessageAdapter;
use App\Messaging\Adapters\SignalAdapter;
use App\Messaging\Adapters\SlackAdapter;
use App\Messaging\Adapters\TelegramAdapter;
use App\Messaging\Adapters\WhatsAppAdapter;
use App\Messaging\MessageRouter;
use App\Messaging\SessionBridge;
use App\Mobile\MobilePairingService;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Setting;
use App\Plugins\PluginManager;
use App\Plugins\PluginSigner;
use App\Plugins\PluginVerifier;
use App\Security\ApiKeyManager;
use App\Security\AuditLogger;
use App\Security\PermissionManager;
use App\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Setting::query()->updateOrCreate(
        ['group' => 'app', 'key' => 'onboarding_completed'],
        ['value' => '1']
    );
});

it('verifies all core services are resolvable from container', function () {
    expect(app(ToolRegistry::class))->toBeInstanceOf(ToolRegistry::class)
        ->and(app(PermissionManager::class))->toBeInstanceOf(PermissionManager::class)
        ->and(app(AuditLogger::class))->toBeInstanceOf(AuditLogger::class)
        ->and(app(ApiKeyManager::class))->toBeInstanceOf(ApiKeyManager::class)
        ->and(app(ProviderManager::class))->toBeInstanceOf(ProviderManager::class)
        ->and(app(MessageRouter::class))->toBeInstanceOf(MessageRouter::class)
        ->and(app(SessionBridge::class))->toBeInstanceOf(SessionBridge::class)
        ->and(app(PluginManager::class))->toBeInstanceOf(PluginManager::class)
        ->and(app(PluginRegistry::class))->toBeInstanceOf(PluginRegistry::class)
        ->and(app(MarketplaceService::class))->toBeInstanceOf(MarketplaceService::class)
        ->and(app(MobilePairingService::class))->toBeInstanceOf(MobilePairingService::class);
});

it('verifies all 6 messaging adapters exist and implement interface', function () {
    $adapters = [
        TelegramAdapter::class,
        DiscordAdapter::class,
        WhatsAppAdapter::class,
        SlackAdapter::class,
        IMessageAdapter::class,
        SignalAdapter::class,
    ];

    foreach ($adapters as $adapterClass) {
        $adapter = new $adapterClass;
        expect($adapter)->toBeInstanceOf(\App\Messaging\Contracts\MessagingAdapter::class)
            ->and($adapter->getName())->toBeString()->not->toBeEmpty()
            ->and($adapter->getCapabilities())->toBeInstanceOf(\App\Messaging\AdapterCapabilities::class);
    }
});

it('verifies all web routes are accessible', function () {
    $this->get('/')->assertRedirect('/chat');
    $this->get('/chat')->assertOk();
    $this->get('/settings')->assertOk();
    $this->get('/mobile/chat')->assertOk();
});

it('verifies all API routes are accessible', function () {
    $this->getJson('/api/mobile/status')->assertOk()
        ->assertJsonStructure(['name', 'version', 'mobile_api']);
});

it('verifies mobile pairing flow end-to-end', function () {
    $service = app(MobilePairingService::class);
    $pairing = $service->generatePairing('127.0.0.1', 8001);

    $pairResponse = $this->postJson('/api/mobile/pair', [
        'token' => $pairing['token'],
        'device_name' => 'Integration Test Device',
    ]);
    $pairResponse->assertOk();

    $sessionToken = $pairResponse->json('session_token');

    $chatResponse = $this->postJson('/api/mobile/chat', [
        'message' => 'Integration test message',
    ], ['Authorization' => 'Bearer '.$sessionToken]);
    $chatResponse->assertOk()->assertJsonStructure(['conversation_id', 'response']);

    $conversationsResponse = $this->getJson('/api/mobile/conversations', [
        'Authorization' => 'Bearer '.$sessionToken,
    ]);
    $conversationsResponse->assertOk();

    $conversationId = $chatResponse->json('conversation_id');
    $messagesResponse = $this->getJson("/api/mobile/conversations/{$conversationId}/messages", [
        'Authorization' => 'Bearer '.$sessionToken,
    ]);
    $messagesResponse->assertOk();
});

it('verifies conversation CRUD works end-to-end', function () {
    $conversation = Conversation::create([
        'title' => 'Final Integration Test',
        'model' => 'claude-sonnet-4-20250514',
        'provider' => 'anthropic',
        'status' => 'active',
    ]);

    Message::create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'Hello',
        'token_count' => 1,
    ]);

    Message::create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'content' => 'Hi there!',
        'token_count' => 3,
    ]);

    $loaded = Conversation::with('messages')->find($conversation->id);
    expect($loaded->messages)->toHaveCount(2)
        ->and($loaded->messages->first()->content)->toBe('Hello')
        ->and($loaded->messages->last()->content)->toBe('Hi there!');
});

it('verifies plugin signer and verifier are resolvable', function () {
    $signer = app(PluginSigner::class);
    $verifier = app(PluginVerifier::class);

    expect($signer)->toBeInstanceOf(PluginSigner::class)
        ->and($verifier)->toBeInstanceOf(PluginVerifier::class);

    $keypair = $signer->generateKeyPair();
    expect($keypair)->toHaveKeys(['public_key', 'secret_key']);
});

it('verifies update service configuration', function () {
    $service = app(UpdateService::class);

    expect($service->currentVersion())->toBeString()->not->toBeEmpty();
});

it('verifies no plaintext secrets in settings table', function () {
    $apiKeyManager = app(ApiKeyManager::class);
    $apiKeyManager->store('openai', 'sk-test-1234567890abcdef');

    $rawValue = Setting::query()
        ->where('group', 'credentials')
        ->where('key', 'openai_api_key')
        ->value('value');

    expect($rawValue)->not->toContain('sk-test-1234567890abcdef');
});

it('verifies config has all required sections', function () {
    expect(config('aegis.name'))->toBe('Aegis')
        ->and(config('aegis.agent'))->toBeArray()
        ->and(config('aegis.providers'))->toBeArray()
        ->and(config('aegis.security'))->toBeArray()
        ->and(config('aegis.messaging'))->toBeArray()
        ->and(config('aegis.plugins'))->toBeArray()
        ->and(config('aegis.marketplace'))->toBeArray()
        ->and(config('aegis.mcp'))->toBeArray()
        ->and(config('aegis.messaging.adapters'))->toHaveCount(6);
});

it('verifies all adapter config entries match existing classes', function () {
    $adapters = config('aegis.messaging.adapters');

    foreach ($adapters as $name => $class) {
        expect(class_exists($class))->toBeTrue("Adapter class {$class} for {$name} does not exist");
    }
});

it('verifies tool registry discovers built-in tools', function () {
    $registry = app(ToolRegistry::class);
    $tools = $registry->all();

    expect($tools)->not->toBeEmpty();
});

it('verifies database tables exist after migration', function () {
    $tables = ['conversations', 'messages', 'memories', 'settings', 'audit_logs', 'tool_permissions'];

    foreach ($tables as $table) {
        expect(\Illuminate\Support\Facades\Schema::hasTable($table))->toBeTrue("Table {$table} missing");
    }
});

it('verifies FTS5 search works on messages', function () {
    $conversation = Conversation::create([
        'title' => 'FTS Test',
        'model' => 'test',
        'provider' => 'test',
        'status' => 'active',
    ]);

    Message::create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'uniquesearchtoken42 is a special keyword',
        'token_count' => 5,
    ]);

    $results = \Illuminate\Support\Facades\DB::select(
        'SELECT rowid FROM messages_fts WHERE messages_fts MATCH ?',
        ['uniquesearchtoken42']
    );

    expect($results)->not->toBeEmpty();
});
