<?php

use App\Agent\AegisAgent;
use App\Agent\ProviderManager;
use App\Messaging\Adapters\DiscordAdapter;
use App\Messaging\Adapters\TelegramAdapter;
use App\Messaging\MessageRouter;
use App\Models\Conversation;
use App\Models\MessagingChannel;
use App\Models\Setting;
use App\Plugins\PluginManager;
use App\Plugins\PluginManifest;
use App\Security\ApiKeyManager;
use App\Security\PermissionDecision;
use App\Security\PermissionManager;
use App\Tools\BrowserSession;
use App\Tools\BrowserTool;
use App\Tools\ToolRegistry;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;

class Phase2EnforcedCsrfTokenMiddleware extends VerifyCsrfToken
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}

uses(RefreshDatabase::class);

it('fails over provider manager chain when primary throws', function () {
    config()->set('aegis.failover_chain', ['openai']);

    $result = app(ProviderManager::class)->failover('anthropic', function (string $provider): string {
        if ($provider === 'anthropic') {
            throw new RuntimeException('primary unavailable');
        }

        return 'resolved:'.$provider;
    });

    expect($result)->toBe('resolved:openai');
});

it('routes telegram webhook through adapter router agent and outbound reply', function () {
    $bot = new class
    {
        public array $sent = [];

        public function sendMessage(string $text, int|string|null $chat_id = null, mixed $message_thread_id = null, mixed $parse_mode = null): void
        {
            $this->sent[] = compact('text', 'chat_id', 'parse_mode');
        }
    };

    registerTelegramAdapter($bot);

    AegisAgent::fake(['Telegram integration response']);

    test()->postJson('/webhook/telegram', [
        'update_id' => 2001,
        'message' => [
            'date' => 1707811200,
            'chat' => ['id' => 9001],
            'from' => ['id' => 44],
            'text' => 'Hello from Telegram',
        ],
    ])
        ->assertStatus(200)
        ->assertJson(['ok' => true]);

    $channel = MessagingChannel::query()
        ->where('platform', 'telegram')
        ->where('platform_channel_id', '9001')
        ->first();

    expect($channel)->not->toBeNull()
        ->and($channel->conversation)->not->toBeNull()
        ->and($bot->sent)->toHaveCount(1)
        ->and($bot->sent[0]['chat_id'])->toBe('9001')
        ->and($bot->sent[0]['text'])->toBe('Telegram integration response');

    AegisAgent::assertPrompted('Hello from Telegram');
});

it('routes discord webhook with valid signature through agent and outbound reply', function () {
    Http::fake([
        'https://discord.com/api/v10/channels/*/messages' => Http::response(['id' => 'discord-message-1'], 200),
    ]);

    $keypair = sodium_crypto_sign_keypair();
    $publicKeyHex = sodium_bin2hex(sodium_crypto_sign_publickey($keypair));
    $secretKey = sodium_crypto_sign_secretkey($keypair);

    config()->set('aegis.messaging.discord.public_key', $publicKeyHex);
    config()->set('aegis.messaging.discord.bot_token', 'discord-test-token');

    registerDiscordAdapter();

    AegisAgent::fake(['Discord integration response']);

    $payload = [
        'type' => 2,
        'channel_id' => 'chan-555',
        'member' => ['user' => ['id' => 'user-999']],
        'data' => [
            'name' => 'aegis',
            'options' => [[
                'name' => 'chat',
                'type' => 1,
                'options' => [['name' => 'message', 'type' => 3, 'value' => 'Hello Discord']],
            ]],
        ],
    ];

    $response = app()->handle(signedDiscordRequest($payload, $secretKey));

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toContain('"ok":true');

    $channel = MessagingChannel::query()
        ->where('platform', 'discord')
        ->where('platform_channel_id', 'chan-555')
        ->first();

    expect($channel)->not->toBeNull()
        ->and($channel->conversation)->not->toBeNull();

    Http::assertSentCount(1);

    AegisAgent::assertPrompted('Hello Discord');
});

it('enforces browser blocked url schemes including file chrome and javascript', function () {
    $session = Mockery::mock(BrowserSession::class);
    $session->shouldNotReceive('navigate');

    $tool = new BrowserTool($session);

    $blocked = [
        'file:///etc/passwd',
        'chrome://settings',
        'about:blank',
        'javascript:alert(1)',
        'data:text/html,<h1>x</h1>',
    ];

    foreach ($blocked as $url) {
        $result = (string) $tool->handle(new \Laravel\Ai\Tools\Request([
            'action' => 'navigate',
            'url' => $url,
        ]));

        expect(strtolower($result))->toContain('blocked');
    }
});

it('rejects discord webhook requests with invalid ed25519 signatures', function () {
    $keypair = sodium_crypto_sign_keypair();
    $publicKeyHex = sodium_bin2hex(sodium_crypto_sign_publickey($keypair));
    $secretKey = sodium_crypto_sign_secretkey($keypair);

    config()->set('aegis.messaging.discord.public_key', $publicKeyHex);
    registerDiscordAdapter();

    $payload = [
        'type' => 2,
        'channel_id' => 'chan-invalid',
        'member' => ['user' => ['id' => 'user-invalid']],
        'data' => [
            'name' => 'aegis',
            'options' => [[
                'name' => 'chat',
                'type' => 1,
                'options' => [['name' => 'message', 'type' => 3, 'value' => 'ignored']],
            ]],
        ],
    ];

    $validRequest = signedDiscordRequest($payload, $secretKey);
    $invalidRequest = Request::create(
        '/webhook/discord',
        'POST',
        [],
        [],
        [],
        [
            'HTTP_X_SIGNATURE_ED25519' => str_repeat('a', 128),
            'HTTP_X_SIGNATURE_TIMESTAMP' => (string) $validRequest->header('X-Signature-Timestamp', ''),
            'CONTENT_TYPE' => 'application/json',
        ],
        $validRequest->getContent(),
    );

    $response = app()->handle($invalidRequest);

    expect($response->getStatusCode())->toBe(401)
        ->and($response->getContent())->toContain('Invalid request signature');
});

it('handles malformed telegram webhook payloads without crashing', function () {
    $bot = new class
    {
        public array $sent = [];

        public function sendMessage(string $text, int|string|null $chat_id = null, mixed $message_thread_id = null, mixed $parse_mode = null): void
        {
            $this->sent[] = compact('text', 'chat_id', 'parse_mode');
        }
    };

    registerTelegramAdapter($bot);

    AegisAgent::fake(['Handled malformed payload']);

    test()->postJson('/webhook/telegram', [
        'update_id' => 3001,
        'message' => [
            'chat' => [],
            'from' => [],
            'text' => null,
        ],
    ])
        ->assertStatus(200)
        ->assertJson(['ok' => true]);

    expect(Conversation::query()->count())->toBe(1)
        ->and($bot->sent)->toHaveCount(1);
});

it('rejects plugin manifests with invalid permissions boundary shape', function () {
    $pluginsPath = base_path('storage/framework/testing/plugins-permission-boundary');
    $pluginPath = $pluginsPath.'/broken';

    File::deleteDirectory($pluginsPath);
    File::makeDirectory($pluginPath, 0755, true);

    File::put($pluginPath.'/plugin.json', json_encode([
        'name' => 'broken',
        'version' => '1.0.0',
        'description' => 'Broken plugin',
        'author' => 'Tests',
        'permissions' => 'all',
        'provider' => 'Broken\\BrokenServiceProvider',
        'tools' => ['broken_tool'],
        'autoload' => ['psr-4' => ['Broken\\' => 'src/']],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    expect(fn () => PluginManifest::fromPath($pluginPath))
        ->toThrow(InvalidArgumentException::class, 'permissions');
});

it('treats xss and sql-like prompt injection text as plain user content in telegram flow', function () {
    $bot = new class
    {
        public array $sent = [];

        public function sendMessage(string $text, int|string|null $chat_id = null, mixed $message_thread_id = null, mixed $parse_mode = null): void
        {
            $this->sent[] = compact('text', 'chat_id', 'parse_mode');
        }
    };

    registerTelegramAdapter($bot);

    $injection = "<script>alert('xss')</script> '; DROP TABLE messages; --";

    AegisAgent::fake(['Injection handled safely']);

    test()->postJson('/webhook/telegram', [
        'update_id' => 4001,
        'message' => [
            'date' => 1707811200,
            'chat' => ['id' => 3210],
            'from' => ['id' => 9876],
            'text' => $injection,
        ],
    ])
        ->assertStatus(200)
        ->assertJson(['ok' => true]);

    $channel = MessagingChannel::query()
        ->where('platform', 'telegram')
        ->where('platform_channel_id', '3210')
        ->firstOrFail();

    expect(Schema::hasTable('messages'))->toBeTrue()
        ->and(Schema::hasTable('conversations'))->toBeTrue()
        ->and($bot->sent)->toHaveCount(1)
        ->and($bot->sent[0]['text'])->toBe('Injection handled safely');

    AegisAgent::assertPrompted($injection);
});

it('enforces csrf token checks for protected post routes', function () {
    Route::post('/phase2/security/csrf-probe', fn () => response('ok'))
        ->middleware(['web', Phase2EnforcedCsrfTokenMiddleware::class]);

    test()->post('/phase2/security/csrf-probe', ['payload' => 'value'])
        ->assertStatus(419);
});

it('keeps api keys encrypted at rest as a phase1 security regression check', function () {
    $plain = 'sk-ant-ABCDEFGHIJKLMNOPQRSTUVWXYZ1234';
    $manager = app(ApiKeyManager::class);

    $manager->store('anthropic', $plain);

    $raw = Setting::query()
        ->where('group', 'credentials')
        ->where('key', 'anthropic_api_key')
        ->value('value');

    expect($manager->retrieve('anthropic'))->toBe($plain)
        ->and($raw)->not->toBe($plain)
        ->and($raw)->not->toContain($plain);
});

it('blocks path traversal payloads at permission manager boundary', function () {
    $decision = app(PermissionManager::class)->check('file_read', 'read', [
        'path' => '../../etc/passwd',
    ]);

    expect($decision)->toBe(PermissionDecision::Denied);
});

it('keeps telegram webhook response latency under two seconds', function () {
    $bot = new class
    {
        public array $sent = [];

        public function sendMessage(string $text, int|string|null $chat_id = null, mixed $message_thread_id = null, mixed $parse_mode = null): void
        {
            $this->sent[] = compact('text', 'chat_id', 'parse_mode');
        }
    };

    registerTelegramAdapter($bot);

    AegisAgent::fake(['Latency benchmark response']);

    $startedAt = microtime(true);
    test()->postJson('/webhook/telegram', [
        'update_id' => 5001,
        'message' => [
            'date' => 1707811200,
            'chat' => ['id' => 7777],
            'from' => ['id' => 8888],
            'text' => 'latency ping',
        ],
    ])
        ->assertStatus(200);
    $elapsedMs = (microtime(true) - $startedAt) * 1000;

    expect($elapsedMs)->toBeLessThan(2000.0)
        ->and($bot->sent)->toHaveCount(1);
});

it('loads plugins under one hundred milliseconds per plugin', function () {
    $pluginsPath = base_path('storage/framework/testing/plugins-phase2-bench');
    File::deleteDirectory($pluginsPath);

    createPluginFixture(
        pluginsPath: $pluginsPath,
        pluginName: 'phase2-bench',
        namespace: 'Phase2Bench',
        providerClass: 'BenchServiceProvider',
        toolClass: 'BenchTool',
        toolName: 'phase2_bench',
    );

    $manager = app()->make(PluginManager::class, ['pluginsPath' => $pluginsPath]);
    $manager->discover();

    $startedAt = microtime(true);
    $manager->load('phase2-bench');
    $elapsedMs = (microtime(true) - $startedAt) * 1000;

    expect($elapsedMs)->toBeLessThan(100.0)
        ->and(app(ToolRegistry::class)->names())->toContain('phase2_bench');
});

function registerTelegramAdapter(object $bot): void
{
    app()->forgetInstance(MessageRouter::class);
    $router = app(MessageRouter::class);
    $router->registerAdapter('telegram', new TelegramAdapter($bot));
}

function registerDiscordAdapter(): void
{
    app()->forgetInstance(MessageRouter::class);
    $router = app(MessageRouter::class);
    $router->registerAdapter('discord', new DiscordAdapter);
}

function signedDiscordRequest(array $payload, string $secretKey): Request
{
    $timestamp = (string) now()->timestamp;
    $raw = json_encode($payload, JSON_THROW_ON_ERROR);
    $signature = sodium_bin2hex(sodium_crypto_sign_detached($timestamp.$raw, $secretKey));

    return Request::create('/webhook/discord', 'POST', [], [], [], [
        'HTTP_X_SIGNATURE_ED25519' => $signature,
        'HTTP_X_SIGNATURE_TIMESTAMP' => $timestamp,
        'CONTENT_TYPE' => 'application/json',
    ], $raw);
}

function createPluginFixture(
    string $pluginsPath,
    string $pluginName,
    string $namespace,
    string $providerClass,
    string $toolClass,
    string $toolName,
): void {
    $pluginPath = $pluginsPath.'/'.$pluginName;

    File::makeDirectory($pluginPath.'/src', 0755, true);

    File::put($pluginPath.'/plugin.json', json_encode([
        'name' => $pluginName,
        'version' => '1.0.0',
        'description' => 'Phase2 plugin fixture',
        'author' => 'Tests',
        'permissions' => ['read'],
        'provider' => "{$namespace}\\{$providerClass}",
        'tools' => [$toolName],
        'autoload' => [
            'psr-4' => [
                "{$namespace}\\" => 'src/',
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    File::put($pluginPath.'/src/'.$providerClass.'.php', "<?php\n\nnamespace {$namespace};\n\nuse App\\Plugins\\PluginServiceProvider;\n\nclass {$providerClass} extends PluginServiceProvider\n{\n    public function pluginName(): string\n    {\n        return '{$pluginName}';\n    }\n\n    public function boot(): void\n    {\n        \$this->registerTool({$toolClass}::class);\n    }\n}\n");

    File::put($pluginPath.'/src/'.$toolClass.'.php', "<?php\n\nnamespace {$namespace};\n\nuse App\\Agent\\ToolResult;\nuse App\\Tools\\BaseTool;\n\nclass {$toolClass} extends BaseTool\n{\n    public function name(): string\n    {\n        return '{$toolName}';\n    }\n\n    public function description(): string\n    {\n        return 'Fixture tool';\n    }\n\n    public function requiredPermission(): string\n    {\n        return 'read';\n    }\n\n    public function parameters(): array\n    {\n        return [\n            'type' => 'object',\n            'required' => ['topic'],\n            'properties' => [\n                'topic' => ['type' => 'string', 'description' => 'Lookup topic'],\n            ],\n        ];\n    }\n\n    public function execute(array \$input): ToolResult\n    {\n        return new ToolResult(true, 'lookup:'.trim((string) (\$input['topic'] ?? '')));\n    }\n}\n");
}
