<?php

use App\Agent\AgentOrchestrator;
use App\Agent\ContextManager;
use App\Agent\ProviderManager;
use App\Agent\StreamBuffer;
use App\Agent\SystemPromptBuilder;
use App\Enums\MessageRole;
use App\Events\ApprovalRequest;
use App\Events\ApprovalResponse;
use App\Messaging\Adapters\DiscordAdapter;
use App\Messaging\Adapters\TelegramAdapter;
use App\Messaging\MessageRouter;
use App\Models\AuditLog;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessagingChannel;
use App\Models\Setting;
use App\Plugins\PluginManifest;
use App\Plugins\PluginManager;
use App\Security\ApiKeyManager;
use App\Security\AuditLogger;
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
use Prism\Prism\Enums\FinishReason;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;
use Prism\Prism\ValueObjects\ToolCall;

class Phase2EnforcedCsrfTokenMiddleware extends VerifyCsrfToken
{
    protected function runningUnitTests(): bool
    {
        return false;
    }
}

uses(RefreshDatabase::class);

it('switches providers mid-conversation and records both assistant turns', function () {
    $conversation = Conversation::factory()->create();

    $fake = Prism::fake([
        TextResponseFake::make()->withText('Anthropic turn'),
        TextResponseFake::make()->withText('OpenAI turn'),
    ]);

    $orchestrator = app(AgentOrchestrator::class);

    $first = $orchestrator->respond('hello provider a', $conversation->id, 'anthropic', 'claude-sonnet-4-20250514');
    $second = $orchestrator->respond('hello provider b', $conversation->id, 'openai', 'gpt-4o');

    expect($first)->toBe('Anthropic turn')
        ->and($second)->toBe('OpenAI turn')
        ->and(Message::query()->where('conversation_id', $conversation->id)->where('role', MessageRole::User)->count())->toBe(2)
        ->and(Message::query()->where('conversation_id', $conversation->id)->where('role', MessageRole::Assistant)->count())->toBe(2);

    $fake->assertRequest(function (array $requests): void {
        expect($requests)->toHaveCount(2)
            ->and($requests[0]->provider())->toBe('anthropic')
            ->and($requests[0]->model())->toBe('claude-sonnet-4-20250514')
            ->and($requests[1]->provider())->toBe('openai')
            ->and($requests[1]->model())->toBe('gpt-4o');
    });
});

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

it('streams responses end to end and persists final assistant message', function () {
    $conversation = Conversation::factory()->create();

    Prism::fake([
        TextResponseFake::make()->withText('stream token response'),
    ])->withFakeChunkSize(3);

    $buffer = new StreamBuffer((string) $conversation->id);
    $chunks = [];

    $response = app(AgentOrchestrator::class)->respondStreaming(
        'stream now',
        $conversation->id,
        $buffer,
        function (string $delta) use (&$chunks): void {
            $chunks[] = $delta;
        },
    );

    $assistant = Message::query()
        ->where('conversation_id', $conversation->id)
        ->where('role', MessageRole::Assistant)
        ->latest('id')
        ->first();

    expect($response)->toBe('stream token response')
        ->and($chunks)->not->toBeEmpty()
        ->and($buffer->read())->toBe('stream token response')
        ->and($buffer->isActive())->toBeFalse()
        ->and($assistant?->content)->toBe('stream token response')
        ->and($assistant?->tool_result['streamed'] ?? null)->toBeTrue()
        ->and($assistant?->tool_result['is_complete'] ?? null)->toBeTrue();
});

it('stops streaming on cancellation and stores partial assistant output', function () {
    $conversation = Conversation::factory()->create();

    Prism::fake([
        TextResponseFake::make()->withText('cancelled stream response'),
    ])->withFakeChunkSize(1);

    $buffer = new StreamBuffer((string) $conversation->id);

    $partial = app(AgentOrchestrator::class)->respondStreaming(
        'cancel stream',
        $conversation->id,
        $buffer,
        function (string $delta, string $content) use ($buffer): void {
            if ($delta !== '' && mb_strlen($content) >= 6) {
                $buffer->cancel();
            }
        },
    );

    $assistant = Message::query()
        ->where('conversation_id', $conversation->id)
        ->where('role', MessageRole::Assistant)
        ->latest('id')
        ->first();

    expect($partial)->toBe('cancel')
        ->and($assistant?->content)->toBe('cancel')
        ->and($assistant?->tool_result['is_complete'] ?? null)->toBeFalse()
        ->and($assistant?->tool_result['cancelled'] ?? null)->toBeTrue();
});

it('executes browser navigate and extract flow through agent tool loop', function () {
    $conversation = Conversation::factory()->create();

    $session = Mockery::mock(BrowserSession::class);
    $session->shouldReceive('navigate')
        ->once()
        ->with('https://example.com')
        ->andReturn([
            'title' => 'Example Domain',
            'url' => 'https://example.com',
        ]);
    $session->shouldReceive('getPageContent')
        ->once()
        ->andReturn('Example Domain content extracted');

    $browserTool = new BrowserTool($session);

    Prism::fake([
        TextResponseFake::make()
            ->withToolCalls([new ToolCall('call_1', 'browser', ['action' => 'navigate', 'url' => 'https://example.com'])])
            ->withFinishReason(FinishReason::ToolCalls),
        TextResponseFake::make()
            ->withToolCalls([new ToolCall('call_2', 'browser', ['action' => 'get_page_content'])])
            ->withFinishReason(FinishReason::ToolCalls),
        TextResponseFake::make()->withText('Found page content: Example Domain content extracted'),
    ]);

    $orchestrator = new AgentOrchestrator(
        new SystemPromptBuilder([$browserTool]),
        new ContextManager,
        [$browserTool],
        null,
        app(PermissionManager::class),
        app(AuditLogger::class),
        fn (ApprovalRequest $request) => new ApprovalResponse($request->requestId, 'allow', true),
    );

    $response = $orchestrator->respond('navigate and extract from example.com', $conversation->id);
    $toolMessages = Message::query()
        ->where('conversation_id', $conversation->id)
        ->where('role', MessageRole::Tool)
        ->orderBy('id')
        ->get();

    expect($response)->toContain('Example Domain content extracted')
        ->and($toolMessages)->toHaveCount(2)
        ->and($toolMessages[0]->tool_name)->toBe('browser')
        ->and($toolMessages[0]->content)->toContain('Example Domain')
        ->and($toolMessages[1]->content)->toContain('content extracted')
        ->and(AuditLog::query()->where('conversation_id', $conversation->id)->where('action', 'tool.executed')->count())->toBe(2);
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

    Prism::fake([
        TextResponseFake::make()->withText('Telegram integration response'),
    ]);

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

    $conversation = $channel?->conversation;
    $userMessage = Message::query()
        ->where('conversation_id', $conversation?->id)
        ->where('role', MessageRole::User)
        ->latest('id')
        ->first();
    $assistantMessage = Message::query()
        ->where('conversation_id', $conversation?->id)
        ->where('role', MessageRole::Assistant)
        ->latest('id')
        ->first();

    expect($channel)->not->toBeNull()
        ->and($conversation)->not->toBeNull()
        ->and($userMessage?->content)->toBe('Hello from Telegram')
        ->and($assistantMessage?->content)->toBe('Telegram integration response')
        ->and($bot->sent)->toHaveCount(1)
        ->and($bot->sent[0]['chat_id'])->toBe('9001')
        ->and($bot->sent[0]['text'])->toBe('Telegram integration response');
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

    Prism::fake([
        TextResponseFake::make()->withText('Discord integration response'),
    ]);

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

    $conversation = $channel?->conversation;
    $userMessage = Message::query()
        ->where('conversation_id', $conversation?->id)
        ->where('role', MessageRole::User)
        ->latest('id')
        ->first();
    $assistantMessage = Message::query()
        ->where('conversation_id', $conversation?->id)
        ->where('role', MessageRole::Assistant)
        ->latest('id')
        ->first();

    expect($channel)->not->toBeNull()
        ->and($userMessage?->content)->toBe('Hello Discord')
        ->and($assistantMessage?->content)->toBe('Discord integration response');

    Http::assertSentCount(1);
});

it('loads plugin registers tool and uses plugin tool in a conversation turn', function () {
    $pluginsPath = base_path('storage/framework/testing/plugins-phase2-integration');
    File::deleteDirectory($pluginsPath);

    createPluginFixture(
        pluginsPath: $pluginsPath,
        pluginName: 'phase2-toolkit',
        namespace: 'Phase2Toolkit',
        providerClass: 'ToolkitServiceProvider',
        toolClass: 'LookupTool',
        toolName: 'phase2_lookup',
    );

    $manager = app()->make(PluginManager::class, ['pluginsPath' => $pluginsPath]);
    $discovered = $manager->discover();
    $manager->load('phase2-toolkit');

    $tool = app(ToolRegistry::class)->get('phase2_lookup');

    expect($discovered)->toHaveKey('phase2-toolkit')
        ->and($tool)->not->toBeNull();

    Prism::fake([
        TextResponseFake::make()
            ->withToolCalls([new ToolCall('plugin_call_1', 'phase2_lookup', ['topic' => 'deploy'])])
            ->withFinishReason(FinishReason::ToolCalls),
        TextResponseFake::make()->withText('Plugin result delivered'),
    ]);

    $conversation = Conversation::factory()->create();
    $response = (new AgentOrchestrator(
        new SystemPromptBuilder([$tool]),
        new ContextManager,
        [$tool],
    ))->respond('use plugin lookup', $conversation->id);

    $toolMessage = Message::query()
        ->where('conversation_id', $conversation->id)
        ->where('role', MessageRole::Tool)
        ->latest('id')
        ->first();

    expect($response)->toBe('Plugin result delivered')
        ->and($toolMessage?->tool_name)->toBe('phase2_lookup')
        ->and($toolMessage?->content)->toContain('lookup:deploy');
});

it('keeps two hundred plus message context window under budget and preserves newest content', function () {
    $manager = new ContextManager;
    $systemPrompt = 'System prompt '.str_repeat('s', 220);

    $messages = collect(range(1, 220))
        ->map(fn (int $index): array => [
            'role' => $index % 2 === 0 ? 'assistant' : 'user',
            'content' => "message {$index} ".str_repeat(chr(97 + ($index % 26)), 140),
        ])
        ->all();

    $contextWindow = $manager->buildContextWindow($systemPrompt, $messages, 6000);
    $contents = collect($contextWindow)->pluck('content')->all();

    expect($messages)->toHaveCount(220)
        ->and($contextWindow)->not->toBeEmpty()
        ->and($manager->totalTokensUsed($systemPrompt, $contextWindow))->toBeLessThanOrEqual(6000)
        ->and(last($contextWindow)['content'])->toBe(last($messages)['content'])
        ->and(in_array($messages[0]['content'], $contents, true))->toBeFalse();
});

it('summarizes dropped context in long conversations before final response', function () {
    config()->set('aegis.providers.openai.models.gpt-4o.context_window', 1200);

    $conversation = Conversation::factory()->create(['summary' => null]);

    for ($index = 1; $index <= 40; $index++) {
        Message::query()->create([
            'conversation_id' => $conversation->id,
            'role' => $index % 2 === 0 ? MessageRole::Assistant : MessageRole::User,
            'content' => "history {$index} ".str_repeat('x', 400),
            'tokens_used' => 100,
        ]);
    }

    Prism::fake([
        TextResponseFake::make()->withText('Key decisions: preserve recent context. Facts learned: user is testing summaries. Open loops: none.'),
        TextResponseFake::make()->withText('Summary path response'),
    ]);

    $response = app(AgentOrchestrator::class)->respond('give me a recap', $conversation->id, 'openai', 'gpt-4o');

    expect($response)->toBe('Summary path response')
        ->and((string) $conversation->fresh()->summary)->toContain('Key decisions');
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
        $result = $tool->execute([
            'action' => 'navigate',
            'url' => $url,
        ]);

        expect($result->success)->toBeFalse()
            ->and((string) $result->error)->toContain('blocked');
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

    Prism::fake([
        TextResponseFake::make()->withText('Handled malformed payload'),
    ]);

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

    Prism::fake([
        TextResponseFake::make()->withText('Injection handled safely'),
    ]);

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

    $message = Message::query()
        ->where('conversation_id', $channel->conversation_id)
        ->where('role', MessageRole::User)
        ->latest('id')
        ->first();

    expect($message?->content)->toBe($injection)
        ->and(Schema::hasTable('messages'))->toBeTrue()
        ->and(Schema::hasTable('conversations'))->toBeTrue();
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

it('keeps streaming first token latency under five hundred milliseconds', function () {
    $conversation = Conversation::factory()->create();

    Prism::fake([
        TextResponseFake::make()->withText('latency check stream output'),
    ])->withFakeChunkSize(1);

    $buffer = new StreamBuffer((string) $conversation->id);
    $firstTokenLatencyMs = null;
    $startedAt = microtime(true);

    app(AgentOrchestrator::class)->respondStreaming(
        'measure first token',
        $conversation->id,
        $buffer,
        function () use ($startedAt, &$firstTokenLatencyMs): void {
            if ($firstTokenLatencyMs === null) {
                $firstTokenLatencyMs = (microtime(true) - $startedAt) * 1000;
            }
        },
    );

    expect($firstTokenLatencyMs)->not->toBeNull()
        ->and($firstTokenLatencyMs)->toBeLessThan(500.0);
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

    Prism::fake([
        TextResponseFake::make()->withText('Latency benchmark response'),
    ]);

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
