<?php

namespace App\Messaging\Adapters;

use App\Messaging\AdapterCapabilities;
use App\Messaging\BaseAdapter;
use App\Messaging\IncomingMessage;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class SlackAdapter extends BaseAdapter
{
    private const THREAD_DELIMITER = '::thread::';

    public function sendMessage(string $channelId, string $content): void
    {
        if (! $this->checkRateLimit()) {
            return;
        }

        [$targetChannel, $threadTs] = $this->decodeTarget($channelId);
        if ($targetChannel === '') {
            return;
        }

        if ($this->isStructuredToolOutput($content)) {
            foreach ($this->splitMessage($this->formatStructuredOutput($content), $this->getCapabilities()->maxMessageLength) as $chunk) {
                $this->safeExecute(function () use ($targetChannel, $threadTs, $chunk): void {
                    $this->slackHttp()->post('/chat.postMessage', $this->buildMessagePayload(
                        channelId: $targetChannel,
                        text: $chunk,
                        threadTs: $threadTs,
                        asCodeBlock: true,
                    ))->throw();
                });
            }

            return;
        }

        foreach ($this->splitMessage($content, $this->getCapabilities()->maxMessageLength) as $chunk) {
            $this->safeExecute(function () use ($targetChannel, $threadTs, $chunk): void {
                $this->slackHttp()->post('/chat.postMessage', $this->buildMessagePayload(
                    channelId: $targetChannel,
                    text: $chunk,
                    threadTs: $threadTs,
                    asCodeBlock: false,
                ))->throw();
            });
        }
    }

    public function sendMedia(string $channelId, string $path, string $type): void
    {
        $this->sendMessage($channelId, sprintf('%s: %s', strtoupper($type), $path));
    }

    public function registerWebhook(string $url): void
    {
    }

    public function handleIncomingMessage(Request $request): IncomingMessage
    {
        if (! $this->verifyRequestSignature($request)) {
            throw new HttpResponseException(response()->json([
                'error' => 'Invalid request signature',
            ], 401));
        }

        if ($this->isUrlVerification($request)) {
            throw new HttpResponseException(response()->json([
                'challenge' => $this->challenge($request),
            ]));
        }

        $payload = $this->parsePayload($request);

        if ($this->isSlashCommand($payload)) {
            $text = trim((string) data_get($payload, 'text', ''));

            return new IncomingMessage(
                platform: 'slack',
                channelId: $this->encodeTarget(
                    channelId: (string) data_get($payload, 'channel_id', ''),
                    threadTs: data_get($payload, 'thread_ts'),
                ),
                senderId: (string) data_get($payload, 'user_id', ''),
                content: $text === '' ? '/aegis' : $text,
                timestamp: $this->resolveTimestamp($payload),
                rawPayload: $payload,
            );
        }

        $event = (array) data_get($payload, 'event', []);
        if ((string) data_get($event, 'type', '') !== 'message') {
            throw new HttpResponseException(response()->json(['ok' => true, 'ignored' => 'unsupported_event']));
        }

        $senderId = (string) data_get($event, 'user', '');
        if ($senderId === '') {
            throw new HttpResponseException(response()->json(['ok' => true, 'ignored' => 'bot_or_empty_user']));
        }

        return new IncomingMessage(
            platform: 'slack',
            channelId: $this->encodeTarget(
                channelId: (string) data_get($event, 'channel', ''),
                threadTs: data_get($event, 'thread_ts', data_get($event, 'ts')),
            ),
            senderId: $senderId,
            content: (string) data_get($event, 'text', ''),
            timestamp: $this->resolveTimestamp($payload),
            rawPayload: $payload,
        );
    }

    public function getName(): string
    {
        return 'slack';
    }

    public function getCapabilities(): AdapterCapabilities
    {
        return new AdapterCapabilities(
            supportsMedia: true,
            supportsButtons: true,
            supportsMarkdown: true,
            maxMessageLength: 2800,
            supportsEditing: true,
        );
    }

    public function verifyRequestSignature(Request $request): bool
    {
        $secret = (string) config('aegis.messaging.slack.signing_secret', '');
        $signature = (string) $request->header('X-Slack-Signature', '');
        $timestamp = (string) $request->header('X-Slack-Request-Timestamp', '');
        $body = $request->getContent();

        if ($secret === '' || $signature === '' || $timestamp === '' || $body === '' || ! ctype_digit($timestamp)) {
            return false;
        }

        if (abs(now()->timestamp - (int) $timestamp) > 300) {
            return false;
        }

        $expected = 'v0='.hash_hmac('sha256', 'v0:'.$timestamp.':'.$body, $secret);

        return hash_equals($expected, $signature);
    }

    public function isUrlVerification(Request $request): bool
    {
        $payload = $this->parsePayload($request);

        return (string) data_get($payload, 'type', '') === 'url_verification';
    }

    public function challenge(Request $request): ?string
    {
        $payload = $this->parsePayload($request);
        $challenge = data_get($payload, 'challenge');

        return is_string($challenge) && $challenge !== '' ? $challenge : null;
    }

    private function parsePayload(Request $request): array
    {
        $contentType = strtolower((string) $request->header('Content-Type', ''));
        $raw = $request->getContent();

        if (str_contains($contentType, 'application/json')) {
            $decoded = json_decode($raw, true);

            return is_array($decoded) ? $decoded : [];
        }

        if (str_contains($contentType, 'application/x-www-form-urlencoded')) {
            $payload = [];
            parse_str($raw, $payload);

            return is_array($payload) ? $payload : [];
        }

        return (array) $request->all();
    }

    private function isSlashCommand(array $payload): bool
    {
        return str_starts_with((string) data_get($payload, 'command', ''), '/');
    }

    private function resolveTimestamp(array $payload): ?Carbon
    {
        $timestamp = data_get($payload, 'event.event_ts', data_get($payload, 'event.ts', data_get($payload, 'event_time')));

        if (is_numeric($timestamp)) {
            return Carbon::createFromTimestampUTC((int) $timestamp);
        }

        return null;
    }

    private function slackHttp(): PendingRequest
    {
        $token = (string) config('aegis.messaging.slack.bot_token', '');

        return Http::withToken($token)
            ->acceptJson()
            ->asJson()
            ->baseUrl('https://slack.com/api');
    }

    private function buildMessagePayload(string $channelId, string $text, ?string $threadTs, bool $asCodeBlock): array
    {
        $mrkdwn = $asCodeBlock ? sprintf("```\n%s\n```", trim($text)) : $text;

        $payload = [
            'channel' => $channelId,
            'text' => $text,
            'blocks' => [
                [
                    'type' => 'section',
                    'text' => [
                        'type' => 'mrkdwn',
                        'text' => $mrkdwn,
                    ],
                ],
            ],
            'unfurl_links' => false,
            'unfurl_media' => false,
        ];

        if ($threadTs !== null && $threadTs !== '') {
            $payload['thread_ts'] = $threadTs;
        }

        return $payload;
    }

    private function encodeTarget(string $channelId, mixed $threadTs): string
    {
        $normalizedChannelId = trim($channelId);
        $normalizedThreadTs = is_string($threadTs) ? trim($threadTs) : '';

        if ($normalizedChannelId === '' || $normalizedThreadTs === '') {
            return $normalizedChannelId;
        }

        return $normalizedChannelId.self::THREAD_DELIMITER.$normalizedThreadTs;
    }

    private function decodeTarget(string $channelId): array
    {
        $parts = explode(self::THREAD_DELIMITER, $channelId, 2);

        return [
            trim((string) ($parts[0] ?? '')),
            isset($parts[1]) ? trim($parts[1]) : null,
        ];
    }

    private function isStructuredToolOutput(string $content): bool
    {
        $trimmed = trim($content);
        if (! str_starts_with($trimmed, '{') && ! str_starts_with($trimmed, '[')) {
            return false;
        }

        json_decode($trimmed, true);

        return json_last_error() === JSON_ERROR_NONE;
    }

    private function formatStructuredOutput(string $content): string
    {
        $decoded = json_decode($content, true);
        $encoded = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        return is_string($encoded) ? $encoded : $content;
    }
}
