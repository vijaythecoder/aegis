<?php

namespace App\Messaging\Adapters;

use App\Enums\MessageRole;
use App\Messaging\AdapterCapabilities;
use App\Messaging\BaseAdapter;
use App\Messaging\IncomingMessage;
use App\Models\Message;
use App\Models\MessagingChannel;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;

class WhatsAppAdapter extends BaseAdapter
{
    public function sendMessage(string $channelId, string $content): void
    {
        if (! $this->checkRateLimit()) {
            return;
        }

        if (! $this->isWithinCustomerServiceWindow($channelId)) {
            return;
        }

        foreach ($this->splitMessage($content, $this->getCapabilities()->maxMessageLength) as $chunk) {
            $this->safeExecute(function () use ($channelId, $chunk): void {
                $this->whatsAppHttp()->post($this->messagesUrl(), [
                    'messaging_product' => 'whatsapp',
                    'to' => $channelId,
                    'type' => 'text',
                    'text' => [
                        'body' => $chunk,
                    ],
                ])->throw();
            });
        }
    }

    public function sendMedia(string $channelId, string $path, string $type): void
    {
        if (! $this->checkRateLimit()) {
            return;
        }

        if (! $this->isWithinCustomerServiceWindow($channelId)) {
            return;
        }

        $mediaType = $this->normalizeMediaType($type);

        $this->safeExecute(function () use ($channelId, $path, $mediaType): void {
            $this->whatsAppHttp()->post($this->messagesUrl(), [
                'messaging_product' => 'whatsapp',
                'to' => $channelId,
                'type' => $mediaType,
                $mediaType => [
                    'link' => $path,
                ],
            ])->throw();
        });
    }

    public function registerWebhook(string $url): void
    {
    }

    public function handleIncomingMessage(Request $request): IncomingMessage
    {
        if (! $this->verifyWebhookSignature($request)) {
            throw new HttpResponseException(response()->json([
                'error' => 'Invalid request signature',
            ], 401));
        }

        $payload = $request->json()->all();
        $message = (array) data_get($payload, 'entry.0.changes.0.value.messages.0', []);
        $contacts = (array) data_get($payload, 'entry.0.changes.0.value.contacts.0', []);
        $timestamp = data_get($message, 'timestamp');
        $from = (string) data_get($message, 'from', '');
        $type = (string) data_get($message, 'type', '');

        return new IncomingMessage(
            platform: 'whatsapp',
            channelId: $from,
            senderId: (string) data_get($contacts, 'wa_id', $from),
            content: $this->extractContent($message, $type),
            mediaUrls: $this->extractMedia($message, $type),
            timestamp: is_numeric($timestamp) ? Carbon::createFromTimestampUTC((int) $timestamp) : null,
            rawPayload: $payload,
        );
    }

    public function getName(): string
    {
        return 'whatsapp';
    }

    public function getCapabilities(): AdapterCapabilities
    {
        return new AdapterCapabilities(
            supportsMedia: true,
            supportsButtons: false,
            supportsMarkdown: false,
            maxMessageLength: 1024,
            supportsEditing: false,
        );
    }

    public function verifyWebhookSignature(Request $request): bool
    {
        $appSecret = (string) config('aegis.messaging.whatsapp.app_secret', '');
        $signature = (string) $request->header('X-Hub-Signature-256', '');
        $body = $request->getContent();

        if ($appSecret === '' || $signature === '' || $body === '') {
            return false;
        }

        $expected = 'sha256='.hash_hmac('sha256', $body, $appSecret);

        return hash_equals($expected, $signature);
    }

    private function whatsAppHttp(): PendingRequest
    {
        $accessToken = (string) config('aegis.messaging.whatsapp.access_token', '');

        return Http::withToken($accessToken)
            ->acceptJson()
            ->asJson()
            ->baseUrl('https://graph.facebook.com/v21.0');
    }

    private function messagesUrl(): string
    {
        $phoneNumberId = (string) config('aegis.messaging.whatsapp.phone_number_id', '');

        return sprintf('/%s/messages', $phoneNumberId);
    }

    private function normalizeMediaType(string $type): string
    {
        $normalized = strtolower($type);

        return match ($normalized) {
            'photo' => 'image',
            default => in_array($normalized, ['image', 'audio', 'video', 'document'], true) ? $normalized : 'document',
        };
    }

    private function extractContent(array $message, string $type): string
    {
        if ($type === 'text') {
            return (string) data_get($message, 'text.body', '');
        }

        return (string) data_get($message, $type.'.caption', '');
    }

    private function extractMedia(array $message, string $type): array
    {
        if ($type === 'text') {
            return [];
        }

        $id = data_get($message, $type.'.id');

        if (! is_string($id) || $id === '') {
            return [];
        }

        return [$id];
    }

    private function isWithinCustomerServiceWindow(string $channelId): bool
    {
        $conversationId = MessagingChannel::query()
            ->where('platform', 'whatsapp')
            ->where('platform_channel_id', $channelId)
            ->where('active', true)
            ->value('conversation_id');

        if (! is_numeric($conversationId)) {
            return false;
        }

        $lastInboundAt = Message::query()
            ->where('conversation_id', (int) $conversationId)
            ->where('role', MessageRole::User->value)
            ->latest('created_at')
            ->value('created_at');

        if (! $lastInboundAt) {
            return false;
        }

        return Carbon::parse($lastInboundAt)->greaterThanOrEqualTo(now()->subDay());
    }
}
