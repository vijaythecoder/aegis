<?php

use App\Messaging\Adapters\SlackAdapter;
use App\Messaging\IncomingMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function slackSignature(string $secret, string $timestamp, string $body): string
{
    return 'v0='.hash_hmac('sha256', 'v0:'.$timestamp.':'.$body, $secret);
}

function signedSlackJsonRequest(array $payload, string $secret): Request
{
    $timestamp = (string) now()->timestamp;
    $raw = json_encode($payload, JSON_THROW_ON_ERROR);

    return Request::create('/webhook/slack', 'POST', [], [], [], [
        'HTTP_X_SLACK_SIGNATURE' => slackSignature($secret, $timestamp, $raw),
        'HTTP_X_SLACK_REQUEST_TIMESTAMP' => $timestamp,
        'CONTENT_TYPE' => 'application/json',
    ], $raw);
}

it('validates signed slack requests and rejects invalid signatures', function () {
    config()->set('aegis.messaging.slack.signing_secret', 'slack-secret');

    $timestamp = (string) now()->timestamp;
    $raw = json_encode(['type' => 'event_callback'], JSON_THROW_ON_ERROR);
    $request = Request::create('/webhook/slack', 'POST', [], [], [], [
        'HTTP_X_SLACK_SIGNATURE' => slackSignature('slack-secret', $timestamp, $raw),
        'HTTP_X_SLACK_REQUEST_TIMESTAMP' => $timestamp,
        'CONTENT_TYPE' => 'application/json',
    ], $raw);
    $invalidRequest = Request::create('/webhook/slack', 'POST', [], [], [], [
        'HTTP_X_SLACK_SIGNATURE' => 'v0=invalid',
        'HTTP_X_SLACK_REQUEST_TIMESTAMP' => $timestamp,
        'CONTENT_TYPE' => 'application/json',
    ], $raw);

    $adapter = new SlackAdapter;

    expect($adapter->verifyRequestSignature($request))->toBeTrue()
        ->and($adapter->verifyRequestSignature($invalidRequest))->toBeFalse();
});

it('parses slack event callback payload into incoming message', function () {
    config()->set('aegis.messaging.slack.signing_secret', 'slack-secret');

    $payload = [
        'type' => 'event_callback',
        'event_time' => 1707811200,
        'event' => [
            'type' => 'message',
            'user' => 'U123',
            'channel' => 'C555',
            'text' => 'hello from slack',
            'ts' => '1707811200.000100',
            'thread_ts' => '1707811200.000050',
        ],
    ];

    $incoming = (new SlackAdapter)->handleIncomingMessage(signedSlackJsonRequest($payload, 'slack-secret'));

    expect($incoming)->toBeInstanceOf(IncomingMessage::class)
        ->and($incoming->platform)->toBe('slack')
        ->and($incoming->channelId)->toBe('C555::thread::1707811200.000050')
        ->and($incoming->senderId)->toBe('U123')
        ->and($incoming->content)->toBe('hello from slack');
});

it('extracts slash command text from form payload', function () {
    config()->set('aegis.messaging.slack.signing_secret', 'slack-secret');

    $body = http_build_query([
        'token' => 'ignored',
        'command' => '/aegis',
        'text' => 'run diagnostics',
        'channel_id' => 'C777',
        'user_id' => 'U999',
    ]);
    $timestamp = (string) now()->timestamp;
    $request = Request::create('/webhook/slack', 'POST', [], [], [], [
        'HTTP_X_SLACK_SIGNATURE' => slackSignature('slack-secret', $timestamp, $body),
        'HTTP_X_SLACK_REQUEST_TIMESTAMP' => $timestamp,
        'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
    ], $body);

    $incoming = (new SlackAdapter)->handleIncomingMessage($request);

    expect($incoming->channelId)->toBe('C777')
        ->and($incoming->senderId)->toBe('U999')
        ->and($incoming->content)->toBe('run diagnostics');
});

it('sends thread replies through slack chat post message api with blocks', function () {
    config()->set('aegis.messaging.slack.bot_token', 'xoxb-test-token');

    Http::fake([
        'https://slack.com/api/chat.postMessage' => Http::response(['ok' => true, 'ts' => '1707811201.000200'], 200),
    ]);

    (new SlackAdapter)->sendMessage('C111::thread::1707811200.000100', 'reply in thread');

    Http::assertSent(function ($request) {
        $body = $request->data();

        return $request->url() === 'https://slack.com/api/chat.postMessage'
            && $request->hasHeader('Authorization', 'Bearer xoxb-test-token')
            && ($body['channel'] ?? null) === 'C111'
            && ($body['thread_ts'] ?? null) === '1707811200.000100'
            && ($body['text'] ?? null) === 'reply in thread'
            && data_get($body, 'blocks.0.type') === 'section'
            && data_get($body, 'blocks.0.text.type') === 'mrkdwn';
    });
});

it('handles slack url verification challenge on route', function () {
    config()->set('aegis.messaging.slack.signing_secret', 'slack-secret');

    $payload = [
        'type' => 'url_verification',
        'challenge' => 'challenge-token-123',
    ];

    $request = signedSlackJsonRequest($payload, 'slack-secret');
    $response = app()->handle($request);

    expect($response->getStatusCode())->toBe(200)
        ->and($response->getContent())->toContain('challenge-token-123');
});

it('rejects unsigned slack payload in adapter handler', function () {
    config()->set('aegis.messaging.slack.signing_secret', 'slack-secret');

    $request = Request::create('/webhook/slack', 'POST', [], [], [], [
        'HTTP_X_SLACK_SIGNATURE' => 'v0=bad-signature',
        'HTTP_X_SLACK_REQUEST_TIMESTAMP' => (string) now()->timestamp,
        'CONTENT_TYPE' => 'application/json',
    ], json_encode([
        'type' => 'event_callback',
        'event' => ['type' => 'message', 'user' => 'U123', 'channel' => 'C555', 'text' => 'bad'],
    ], JSON_THROW_ON_ERROR));

    try {
        (new SlackAdapter)->handleIncomingMessage($request);
        $this->fail('Expected invalid signature to be rejected');
    } catch (HttpResponseException $exception) {
        expect($exception->getResponse()->getStatusCode())->toBe(401);
    }
});
