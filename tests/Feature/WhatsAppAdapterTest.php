<?php

use App\Messaging\Adapters\WhatsAppAdapter;
use App\Messaging\IncomingMessage;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\MessagingChannel;
use App\Enums\MessageRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function seedWhatsAppInboundWindow(string $channelId, Carbon $inboundAt): void
{
    $conversation = Conversation::factory()->create([
        'title' => 'whatsapp:'.$channelId,
        'last_message_at' => $inboundAt,
    ]);

    MessagingChannel::query()->create([
        'platform' => 'whatsapp',
        'platform_channel_id' => $channelId,
        'platform_user_id' => $channelId,
        'conversation_id' => $conversation->id,
        'active' => true,
    ]);

    $message = Message::query()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::User,
        'content' => 'Inbound customer message',
        'tokens_used' => 3,
    ]);

    $message->forceFill([
        'created_at' => $inboundAt,
        'updated_at' => $inboundAt,
    ])->save();
}

it('parses whatsapp webhook payload into incoming message', function () {
    config()->set('aegis.messaging.whatsapp.app_secret', 'secret-key');

    $payload = [
        'object' => 'whatsapp_business_account',
        'entry' => [[
            'id' => 'business-id',
            'changes' => [[
                'value' => [
                    'messaging_product' => 'whatsapp',
                    'metadata' => ['phone_number_id' => 'phone-id'],
                    'contacts' => [[
                        'wa_id' => '15551230000',
                    ]],
                    'messages' => [[
                        'from' => '15551230000',
                        'id' => 'wamid.abc',
                        'timestamp' => '1707811200',
                        'text' => ['body' => 'Hello from WhatsApp'],
                        'type' => 'text',
                    ]],
                ],
                'field' => 'messages',
            ]],
        ]],
    ];

    $raw = json_encode($payload, JSON_THROW_ON_ERROR);
    $signature = 'sha256='.hash_hmac('sha256', $raw, 'secret-key');

    $request = Request::create('/webhook/whatsapp', 'POST', [], [], [], [
        'HTTP_X_HUB_SIGNATURE_256' => $signature,
        'CONTENT_TYPE' => 'application/json',
    ], $raw);

    $incoming = (new WhatsAppAdapter)->handleIncomingMessage($request);

    expect($incoming)->toBeInstanceOf(IncomingMessage::class)
        ->and($incoming->platform)->toBe('whatsapp')
        ->and($incoming->channelId)->toBe('15551230000')
        ->and($incoming->senderId)->toBe('15551230000')
        ->and($incoming->content)->toBe('Hello from WhatsApp')
        ->and($incoming->rawPayload['object'])->toBe('whatsapp_business_account');
});

it('rejects webhook requests with invalid signature', function () {
    config()->set('aegis.messaging.whatsapp.app_secret', 'secret-key');

    $raw = json_encode([
        'object' => 'whatsapp_business_account',
        'entry' => [['changes' => [['value' => ['messages' => [['type' => 'text']]]]]]],
    ], JSON_THROW_ON_ERROR);

    $request = Request::create('/webhook/whatsapp', 'POST', [], [], [], [
        'HTTP_X_HUB_SIGNATURE_256' => 'sha256=invalid',
        'CONTENT_TYPE' => 'application/json',
    ], $raw);

    try {
        (new WhatsAppAdapter)->handleIncomingMessage($request);
        $this->fail('Expected invalid signature to be rejected');
    } catch (HttpResponseException $exception) {
        expect($exception->getResponse()->getStatusCode())->toBe(401);
    }
});

it('sends long text messages split at whatsapp 1024-char limit', function () {
    config()->set('aegis.messaging.whatsapp.access_token', 'test-token');
    config()->set('aegis.messaging.whatsapp.phone_number_id', '123456789');
    seedWhatsAppInboundWindow('15551230000', now()->subMinutes(5));

    Http::fake([
        'https://graph.facebook.com/v21.0/*/messages' => Http::response(['messages' => [['id' => 'wamid.1']]], 200),
    ]);

    (new WhatsAppAdapter)->sendMessage('15551230000', str_repeat('x', 2500));

    Http::assertSentCount(3);
    Http::assertSent(function ($request) {
        $body = $request->data();

        return $request->url() === 'https://graph.facebook.com/v21.0/123456789/messages'
            && ($body['messaging_product'] ?? null) === 'whatsapp'
            && ($body['to'] ?? null) === '15551230000'
            && ($body['type'] ?? null) === 'text'
            && is_string(data_get($body, 'text.body'))
            && mb_strlen((string) data_get($body, 'text.body')) <= 1024;
    });
});

it('sends media messages through whatsapp cloud api', function () {
    config()->set('aegis.messaging.whatsapp.access_token', 'test-token');
    config()->set('aegis.messaging.whatsapp.phone_number_id', '123456789');
    seedWhatsAppInboundWindow('15551230000', now()->subMinutes(5));

    Http::fake([
        'https://graph.facebook.com/v21.0/*/messages' => Http::response(['messages' => [['id' => 'wamid.2']]], 200),
    ]);

    (new WhatsAppAdapter)->sendMedia('15551230000', 'https://cdn.example.com/image.png', 'image');

    Http::assertSent(function ($request) {
        $body = $request->data();

        return ($body['type'] ?? null) === 'image'
            && data_get($body, 'image.link') === 'https://cdn.example.com/image.png';
    });
});

it('returns whatsapp adapter capabilities', function () {
    $capabilities = (new WhatsAppAdapter)->getCapabilities();

    expect($capabilities->supportsMedia)->toBeTrue()
        ->and($capabilities->supportsButtons)->toBeFalse()
        ->and($capabilities->supportsMarkdown)->toBeFalse()
        ->and($capabilities->maxMessageLength)->toBe(1024)
        ->and($capabilities->supportsEditing)->toBeFalse();
});

it('verifies whatsapp webhook challenge route', function () {
    config()->set('aegis.messaging.whatsapp.verify_token', 'verify-me');

    $success = app()->handle(Request::create(
        '/webhook/whatsapp?hub.mode=subscribe&hub.verify_token=verify-me&hub.challenge=abc123',
        'GET',
    ));
    $failure = app()->handle(Request::create(
        '/webhook/whatsapp?hub.mode=subscribe&hub.verify_token=wrong&hub.challenge=abc123',
        'GET',
    ));

    expect($success->getStatusCode())->toBe(200)
        ->and($success->getContent())->toContain('abc123')
        ->and($failure->getStatusCode())->toBe(403);
});

it('allows outbound send within 24-hour customer service window', function () {
    config()->set('aegis.messaging.whatsapp.access_token', 'test-token');
    config()->set('aegis.messaging.whatsapp.phone_number_id', '123456789');
    seedWhatsAppInboundWindow('15550001111', now()->subHours(2));

    Http::fake([
        'https://graph.facebook.com/v21.0/*/messages' => Http::response(['messages' => [['id' => 'wamid.3']]], 200),
    ]);

    (new WhatsAppAdapter)->sendMessage('15550001111', 'Reply within window');

    Http::assertSentCount(1);
});

it('blocks outbound send outside 24-hour customer service window', function () {
    config()->set('aegis.messaging.whatsapp.access_token', 'test-token');
    config()->set('aegis.messaging.whatsapp.phone_number_id', '123456789');
    seedWhatsAppInboundWindow('15550002222', now()->subHours(25));

    Http::fake([
        'https://graph.facebook.com/v21.0/*/messages' => Http::response(['messages' => [['id' => 'wamid.4']]], 200),
    ]);

    (new WhatsAppAdapter)->sendMessage('15550002222', 'Should be blocked');

    Http::assertNothingSent();
});
