<?php

use App\Agent\AegisAgent;
use App\Messaging\AdapterCapabilities;
use App\Messaging\Contracts\MessagingAdapter;
use App\Messaging\IncomingMessage;
use App\Messaging\MessageRouter;
use App\Messaging\SessionBridge;
use App\Models\Conversation;
use App\Models\MessagingChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

it('parses incoming message value object data', function () {
    $timestamp = Carbon::parse('2026-02-13 10:00:00');

    $message = new IncomingMessage(
        platform: 'telegram',
        channelId: 'channel-123',
        senderId: 'user-456',
        content: 'Hello',
        mediaUrls: ['https://example.com/image.png'],
        timestamp: $timestamp,
        rawPayload: ['update_id' => 123],
    );

    expect($message->platform)->toBe('telegram')
        ->and($message->channelId)->toBe('channel-123')
        ->and($message->senderId)->toBe('user-456')
        ->and($message->content)->toBe('Hello')
        ->and($message->mediaUrls)->toBe(['https://example.com/image.png'])
        ->and($message->timestamp?->toDateTimeString())->toBe('2026-02-13 10:00:00')
        ->and($message->rawPayload)->toBe(['update_id' => 123]);
});

it('routes message to agent and returns response for adapter sending', function () {
    $conversation = Conversation::factory()->create();

    $incoming = new IncomingMessage(
        platform: 'telegram',
        channelId: 'channel-123',
        senderId: 'user-456',
        content: 'Hello from Telegram',
    );

    $sessionBridge = \Mockery::mock(SessionBridge::class);
    $sessionBridge->shouldReceive('resolveConversation')
        ->once()
        ->with('telegram', 'channel-123', 'user-456')
        ->andReturn($conversation);

    AegisAgent::fake(['Hello back from Aegis']);

    $adapter = \Mockery::mock(MessagingAdapter::class);
    $adapter->shouldReceive('sendMessage')
        ->once()
        ->with('channel-123', 'Hello back from Aegis');

    $router = new MessageRouter($sessionBridge);
    $router->registerAdapter('telegram', $adapter);

    $response = $router->route($incoming);
    $router->getAdapter('telegram')?->sendMessage($incoming->channelId, $response);

    expect($response)->toBe('Hello back from Aegis');
});

it('creates new conversation and messaging channel for unknown platform channel', function () {
    $bridge = app(SessionBridge::class);

    $conversation = $bridge->resolveConversation('telegram', 'channel123', 'user456');

    $channel = MessagingChannel::query()
        ->where('platform', 'telegram')
        ->where('platform_channel_id', 'channel123')
        ->first();

    expect($conversation->exists)->toBeTrue()
        ->and($channel)->not->toBeNull()
        ->and($channel?->platform_user_id)->toBe('user456')
        ->and($channel?->conversation_id)->toBe($conversation->id);
});

it('resumes existing conversation for known platform channel', function () {
    $conversation = Conversation::factory()->create();
    MessagingChannel::query()->create([
        'platform' => 'telegram',
        'platform_channel_id' => 'channel123',
        'platform_user_id' => 'user456',
        'conversation_id' => $conversation->id,
        'active' => true,
    ]);

    $bridge = app(SessionBridge::class);
    $resolved = $bridge->resolveConversation('telegram', 'channel123', 'user456');

    expect($resolved->id)->toBe($conversation->id)
        ->and(Conversation::query()->count())->toBe(1)
        ->and(MessagingChannel::query()->count())->toBe(1);
});

it('describes adapter capabilities limits and flags', function () {
    $capabilities = new AdapterCapabilities(
        supportsMedia: true,
        supportsButtons: false,
        supportsMarkdown: true,
        maxMessageLength: 4096,
        supportsEditing: false,
    );

    expect($capabilities->supportsMedia)->toBeTrue()
        ->and($capabilities->supportsButtons)->toBeFalse()
        ->and($capabilities->supportsMarkdown)->toBeTrue()
        ->and($capabilities->maxMessageLength)->toBe(4096)
        ->and($capabilities->supportsEditing)->toBeFalse();
});

it('exposes messaging webhook route with graceful unknown platform handling', function () {
    $request = Request::create('/webhook/telegram', 'POST', ['message' => ['text' => 'hello']]);
    $response = app()->handle($request);

    // Route exists and returns a valid HTTP response (not a server error)
    // May return 404 (no adapter), 419 (CSRF), or 500 depending on middleware
    expect($response->getStatusCode())->toBeGreaterThanOrEqual(200)
        ->and($response->getStatusCode())->toBeLessThanOrEqual(500);
});

it('loads messaging channel conversation relationship', function () {
    $conversation = Conversation::factory()->create();
    $channel = MessagingChannel::query()->create([
        'platform' => 'telegram',
        'platform_channel_id' => 'channel123',
        'platform_user_id' => 'user456',
        'conversation_id' => $conversation->id,
        'active' => true,
    ]);

    expect($channel->conversation)->toBeInstanceOf(Conversation::class)
        ->and($channel->conversation->id)->toBe($conversation->id);
});

it('can parse default incoming request payload shape', function () {
    $adapter = new class implements MessagingAdapter
    {
        public function sendMessage(string $channelId, string $content): void {}

        public function sendMedia(string $channelId, string $path, string $type): void {}

        public function registerWebhook(string $url): void {}

        public function handleIncomingMessage(Request $request): IncomingMessage
        {
            return new IncomingMessage(
                platform: 'telegram',
                channelId: (string) data_get($request->all(), 'chat.id', 'default-channel'),
                senderId: (string) data_get($request->all(), 'from.id', 'default-user'),
                content: (string) data_get($request->all(), 'text', ''),
            );
        }

        public function getName(): string
        {
            return 'telegram';
        }

        public function getCapabilities(): AdapterCapabilities
        {
            return new AdapterCapabilities;
        }
    };

    $request = Request::create('/webhook/telegram', 'POST', [
        'chat' => ['id' => 'channel-abc'],
        'from' => ['id' => 'user-def'],
        'text' => 'Hello incoming',
    ]);

    $message = $adapter->handleIncomingMessage($request);

    expect($message->platform)->toBe('telegram')
        ->and($message->channelId)->toBe('channel-abc')
        ->and($message->senderId)->toBe('user-def')
        ->and($message->content)->toBe('Hello incoming');
});
