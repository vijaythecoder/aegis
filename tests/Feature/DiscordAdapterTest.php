<?php

use App\Messaging\Adapters\DiscordAdapter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('parses application command payload into incoming message', function () {
    $keypair = sodium_crypto_sign_keypair();
    $publicKeyHex = sodium_bin2hex(sodium_crypto_sign_publickey($keypair));
    $secretKey = sodium_crypto_sign_secretkey($keypair);
    config()->set('aegis.messaging.discord.public_key', $publicKeyHex);

    $payload = [
        'type' => 2,
        'channel_id' => 'chan-123',
        'member' => ['user' => ['id' => 'user-789']],
        'data' => [
            'name' => 'aegis',
            'options' => [[
                'name' => 'chat',
                'type' => 1,
                'options' => [['name' => 'message', 'type' => 3, 'value' => 'Hello Aegis']],
            ]],
        ],
    ];

    $timestamp = (string) now()->timestamp;
    $raw = json_encode($payload, JSON_THROW_ON_ERROR);
    $signature = sodium_bin2hex(sodium_crypto_sign_detached($timestamp.$raw, $secretKey));
    $request = \Illuminate\Http\Request::create('/webhook/discord', 'POST', [], [], [], [
        'HTTP_X_SIGNATURE_ED25519' => $signature,
        'HTTP_X_SIGNATURE_TIMESTAMP' => $timestamp,
        'CONTENT_TYPE' => 'application/json',
    ], $raw);

    $adapter = new DiscordAdapter;
    $incoming = $adapter->handleIncomingMessage($request);

    expect($incoming->platform)->toBe('discord')
        ->and($incoming->channelId)->toBe('chan-123')
        ->and($incoming->senderId)->toBe('user-789')
        ->and($incoming->content)->toBe('Hello Aegis');
});

it('returns ping interaction response type 1', function () {
    $keypair = sodium_crypto_sign_keypair();
    $publicKeyHex = sodium_bin2hex(sodium_crypto_sign_publickey($keypair));
    $secretKey = sodium_crypto_sign_secretkey($keypair);
    config()->set('aegis.messaging.discord.public_key', $publicKeyHex);

    $adapter = new DiscordAdapter;
    $timestamp = (string) now()->timestamp;
    $raw = json_encode(['type' => 1], JSON_THROW_ON_ERROR);
    $signature = sodium_bin2hex(sodium_crypto_sign_detached($timestamp.$raw, $secretKey));
    $request = \Illuminate\Http\Request::create('/webhook/discord', 'POST', [], [], [], [
        'HTTP_X_SIGNATURE_ED25519' => $signature,
        'HTTP_X_SIGNATURE_TIMESTAMP' => $timestamp,
        'CONTENT_TYPE' => 'application/json',
    ], $raw);

    try {
        $adapter->handleIncomingMessage($request);
        $this->fail('Expected ping request to short-circuit with an HTTP response');
    } catch (HttpResponseException $exception) {
        expect($exception->getResponse()->getStatusCode())->toBe(200)
            ->and($exception->getResponse()->getContent())->toContain('"type":1');
    }
});

it('extracts slash command chat message content', function () {
    $keypair = sodium_crypto_sign_keypair();
    $publicKeyHex = sodium_bin2hex(sodium_crypto_sign_publickey($keypair));
    $secretKey = sodium_crypto_sign_secretkey($keypair);
    config()->set('aegis.messaging.discord.public_key', $publicKeyHex);

    $payload = [
        'type' => 2,
        'channel_id' => 'chan-abc',
        'member' => ['user' => ['id' => 'user-def']],
        'data' => [
            'name' => 'aegis',
            'options' => [[
                'name' => 'chat',
                'type' => 1,
                'options' => [['name' => 'message', 'type' => 3, 'value' => 'Run diagnostics']],
            ]],
        ],
    ];

    $timestamp = (string) now()->timestamp;
    $raw = json_encode($payload, JSON_THROW_ON_ERROR);
    $signature = sodium_bin2hex(sodium_crypto_sign_detached($timestamp.$raw, $secretKey));
    $request = \Illuminate\Http\Request::create('/webhook/discord', 'POST', [], [], [], [
        'HTTP_X_SIGNATURE_ED25519' => $signature,
        'HTTP_X_SIGNATURE_TIMESTAMP' => $timestamp,
        'CONTENT_TYPE' => 'application/json',
    ], $raw);

    $incoming = (new DiscordAdapter)->handleIncomingMessage($request);

    expect($incoming->content)->toBe('Run diagnostics');
});

it('splits long outgoing messages at discord 2000-char limit', function () {
    config()->set('aegis.messaging.discord.bot_token', 'bot-token');

    Http::fake([
        'https://discord.com/api/v10/channels/*/messages' => Http::response(['id' => '1'], 200),
    ]);

    $content = str_repeat('a', 4505);
    (new DiscordAdapter)->sendMessage('chan-1', $content);

    Http::assertSentCount(3);
    Http::assertSent(function ($request) {
        $body = $request->data();

        return isset($body['content'])
            && is_string($body['content'])
            && mb_strlen($body['content']) <= 2000;
    });
});

it('returns discord adapter capabilities', function () {
    $capabilities = (new DiscordAdapter)->getCapabilities();

    expect($capabilities->supportsMedia)->toBeTrue()
        ->and($capabilities->supportsButtons)->toBeTrue()
        ->and($capabilities->supportsMarkdown)->toBeTrue()
        ->and($capabilities->maxMessageLength)->toBe(2000)
        ->and($capabilities->supportsEditing)->toBeTrue();
});

it('rejects webhook requests with invalid signature', function () {
    $keypair = sodium_crypto_sign_keypair();
    $publicKeyHex = sodium_bin2hex(sodium_crypto_sign_publickey($keypair));
    config()->set('aegis.messaging.discord.public_key', $publicKeyHex);

    $raw = json_encode(['type' => 2, 'channel_id' => 'chan'], JSON_THROW_ON_ERROR);
    $request = \Illuminate\Http\Request::create('/webhook/discord', 'POST', [], [], [], [
        'HTTP_X_SIGNATURE_ED25519' => str_repeat('a', 128),
        'HTTP_X_SIGNATURE_TIMESTAMP' => '1234567890',
        'CONTENT_TYPE' => 'application/json',
    ], $raw);

    try {
        (new DiscordAdapter)->handleIncomingMessage($request);
        $this->fail('Expected invalid signatures to be rejected');
    } catch (HttpResponseException $exception) {
        expect($exception->getResponse()->getStatusCode())->toBe(401);
    }
});
