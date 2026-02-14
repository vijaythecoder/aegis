<?php

namespace App\Messaging\Adapters;

use App\Messaging\AdapterCapabilities;
use App\Messaging\BaseAdapter;
use App\Messaging\IncomingMessage;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Http\Exceptions\HttpResponseException;

class DiscordAdapter extends BaseAdapter
{
    public function sendMessage(string $channelId, string $content): void
    {
        if (! $this->checkRateLimit()) {
            return;
        }

        if ($this->isStructuredToolOutput($content)) {
            $this->safeExecute(function () use ($channelId, $content): void {
                $this->discordHttp()->post($this->channelMessageUrl($channelId), [
                    'embeds' => [
                        [
                            'title' => 'Aegis Tool Output',
                            'description' => $this->formatStructuredOutput($content),
                            'color' => 5793266,
                        ],
                    ],
                ])->throw();
            });

            return;
        }

        foreach ($this->splitMessage($content, $this->getCapabilities()->maxMessageLength) as $chunk) {
            $this->safeExecute(function () use ($channelId, $chunk): void {
                $this->discordHttp()->post($this->channelMessageUrl($channelId), [
                    'content' => $chunk,
                ])->throw();
            });
        }
    }

    public function sendMedia(string $channelId, string $path, string $type): void
    {
        if (! $this->checkRateLimit()) {
            return;
        }

        $this->safeExecute(function () use ($channelId, $path, $type): void {
            $payload = [
                'content' => sprintf('Uploaded by Aegis (%s)', $type),
            ];

            $this->discordHttp()
                ->attach('files[0]', file_get_contents($path) ?: '', basename($path), ['Content-Type' => $type])
                ->post($this->channelMessageUrl($channelId), $payload)
                ->throw();
        });
    }

    public function registerWebhook(string $url): void
    {
    }

    public function handleIncomingMessage(Request $request): IncomingMessage
    {
        if (! $this->verifyInteractionSignature($request)) {
            throw new HttpResponseException(response()->json([
                'error' => 'Invalid request signature',
            ], 401));
        }

        $payload = $request->json()->all();
        $type = (int) data_get($payload, 'type', 0);

        if ($type === 1) {
            throw new HttpResponseException(response()->json(['type' => 1]));
        }

        if ($type !== 2) {
            throw new HttpResponseException(response()->json([
                'error' => 'Unsupported Discord interaction type',
            ], 400));
        }

        return new IncomingMessage(
            platform: 'discord',
            channelId: (string) data_get($payload, 'channel_id', ''),
            senderId: (string) data_get($payload, 'member.user.id', data_get($payload, 'user.id', '')),
            content: $this->extractCommandContent($payload),
            rawPayload: $payload,
        );
    }

    public function getName(): string
    {
        return 'discord';
    }

    public function getCapabilities(): AdapterCapabilities
    {
        return new AdapterCapabilities(
            supportsMedia: true,
            supportsButtons: true,
            supportsMarkdown: true,
            maxMessageLength: 2000,
            supportsEditing: true,
        );
    }

    private function discordHttp(): PendingRequest
    {
        $token = (string) config('aegis.messaging.discord.bot_token', '');

        return Http::withToken($token)
            ->acceptJson()
            ->asJson()
            ->baseUrl('https://discord.com/api/v10');
    }

    private function channelMessageUrl(string $channelId): string
    {
        return sprintf('/channels/%s/messages', $channelId);
    }

    private function verifyInteractionSignature(Request $request): bool
    {
        $publicKey = (string) config('aegis.messaging.discord.public_key', '');
        $signature = (string) $request->header('X-Signature-Ed25519', '');
        $timestamp = (string) $request->header('X-Signature-Timestamp', '');
        $body = $request->getContent();

        if ($publicKey === '' || $signature === '' || $timestamp === '' || $body === '') {
            return false;
        }

        if (! ctype_xdigit($publicKey) || ! ctype_xdigit($signature)) {
            return false;
        }

        return sodium_crypto_sign_verify_detached(
            sodium_hex2bin($signature),
            $timestamp.$body,
            sodium_hex2bin($publicKey),
        );
    }

    private function extractCommandContent(array $payload): string
    {
        $command = (string) data_get($payload, 'data.name', 'aegis');
        $first = data_get($payload, 'data.options.0', []);
        $subcommand = (string) data_get($first, 'name', 'chat');

        if ($subcommand === 'chat') {
            $message = (string) data_get($first, 'options.0.value', '');

            return trim($message) !== '' ? $message : '/aegis chat';
        }

        if ($subcommand === 'new') {
            return '/aegis new';
        }

        if ($subcommand === 'history') {
            return '/aegis history';
        }

        return trim(sprintf('/%s %s', $command, $subcommand));
    }

    private function isStructuredToolOutput(string $content): bool
    {
        if (! str_starts_with(trim($content), '{') && ! str_starts_with(trim($content), '[')) {
            return false;
        }

        json_decode($content, true);

        return json_last_error() === JSON_ERROR_NONE;
    }

    private function formatStructuredOutput(string $content): string
    {
        $decoded = json_decode($content, true);
        $encoded = json_encode($decoded, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if (! is_string($encoded)) {
            $encoded = $content;
        }

        $formatted = sprintf("```json\n%s\n```", $encoded);

        return mb_substr($formatted, 0, 4096);
    }
}
