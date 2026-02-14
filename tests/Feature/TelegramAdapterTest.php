<?php

use App\Messaging\Adapters\TelegramAdapter;
use App\Messaging\IncomingMessage;
use App\Models\Conversation;
use App\Models\MessagingChannel;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;

uses(RefreshDatabase::class);

it('parses telegram webhook payload into incoming message', function () {
    $adapter = new TelegramAdapter;

    $request = Request::create('/webhook/telegram', 'POST', [
        'update_id' => 42,
        'message' => [
            'date' => 1707811200,
            'chat' => ['id' => 1001],
            'from' => ['id' => 5001],
            'text' => 'Hello bot',
        ],
    ]);

    $incoming = $adapter->handleIncomingMessage($request);

    expect($incoming)->toBeInstanceOf(IncomingMessage::class)
        ->and($incoming->platform)->toBe('telegram')
        ->and($incoming->channelId)->toBe('1001')
        ->and($incoming->senderId)->toBe('5001')
        ->and($incoming->content)->toBe('Hello bot')
        ->and($incoming->rawPayload['update_id'])->toBe(42);
});

it('sends messages through bot api client', function () {
    $bot = new class
    {
        public array $sent = [];

        public function sendMessage(string $text, int|string|null $chat_id = null, mixed $message_thread_id = null, mixed $parse_mode = null): void
        {
            $this->sent[] = compact('text', 'chat_id', 'parse_mode');
        }
    };

    $adapter = new TelegramAdapter($bot);
    $adapter->sendMessage('telegram-123', 'Hello from Aegis');

    expect($bot->sent)->toHaveCount(1)
        ->and($bot->sent[0]['chat_id'])->toBe('telegram-123')
        ->and($bot->sent[0]['text'])->toBe('Hello from Aegis')
        ->and($bot->sent[0]['parse_mode'])->toBe('MarkdownV2');
});

it('splits long messages to telegram max length', function () {
    $bot = new class
    {
        public array $sent = [];

        public function sendMessage(string $text, int|string|null $chat_id = null, mixed $message_thread_id = null, mixed $parse_mode = null): void
        {
            $this->sent[] = compact('text', 'chat_id', 'parse_mode');
        }
    };

    $adapter = new TelegramAdapter($bot);
    $adapter->sendMessage('telegram-123', str_repeat('x', 5000));

    expect($bot->sent)->toHaveCount(2)
        ->and(strlen($bot->sent[0]['text']) <= 4096)->toBeTrue()
        ->and(strlen($bot->sent[1]['text']) <= 4096)->toBeTrue();
});

it('handles start command with welcome response', function () {
    $adapter = new TelegramAdapter;

    $incoming = new IncomingMessage(
        platform: 'telegram',
        channelId: '1001',
        senderId: '5001',
        content: '/start',
    );

    $response = $adapter->handleCommand($incoming);

    expect($response)->not->toBeNull()
        ->and($response)->toContain('Welcome to Aegis Telegram');
});

it('handles new command and creates fresh conversation mapping', function () {
    $adapter = new TelegramAdapter;

    Conversation::factory()->create();

    $incoming = new IncomingMessage(
        platform: 'telegram',
        channelId: '1001',
        senderId: '5001',
        content: '/new',
    );

    $response = $adapter->handleCommand($incoming);

    $channel = MessagingChannel::query()
        ->where('platform', 'telegram')
        ->where('platform_channel_id', '1001')
        ->first();

    expect($response)->toContain('new conversation')
        ->and($channel)->not->toBeNull()
        ->and($channel?->platform_user_id)->toBe('5001')
        ->and($channel?->conversation)->not->toBeNull();
});

it('returns capabilities for telegram adapter', function () {
    $adapter = new TelegramAdapter;
    $capabilities = $adapter->getCapabilities();

    expect($capabilities->supportsMedia)->toBeTrue()
        ->and($capabilities->supportsButtons)->toBeTrue()
        ->and($capabilities->supportsMarkdown)->toBeTrue()
        ->and($capabilities->maxMessageLength)->toBe(4096)
        ->and($capabilities->supportsEditing)->toBeFalse();
});

it('registers webhook through bot api client', function () {
    $bot = new class
    {
        public array $webhooks = [];

        public function setWebhook(string $url): void
        {
            $this->webhooks[] = $url;
        }
    };

    $adapter = new TelegramAdapter($bot);
    $adapter->registerWebhook('https://example.com/webhook/telegram');

    expect($bot->webhooks)->toBe(['https://example.com/webhook/telegram']);
});
