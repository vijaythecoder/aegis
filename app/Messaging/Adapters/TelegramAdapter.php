<?php

namespace App\Messaging\Adapters;

use App\Messaging\AdapterCapabilities;
use App\Messaging\BaseAdapter;
use App\Messaging\IncomingMessage;
use App\Messaging\MessageRouter;
use App\Models\Conversation;
use App\Models\MessagingChannel;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use RuntimeException;

class TelegramAdapter extends BaseAdapter
{
    public function __construct(
        private mixed $bot = null,
    ) {}

    public function sendMessage(string $channelId, string $content, ?string $parseMode = 'MarkdownV2'): void
    {
        if (! $this->checkRateLimit()) {
            return;
        }

        foreach ($this->splitMessage($content, $this->getCapabilities()->maxMessageLength) as $chunk) {
            $this->safeExecute(fn () => $this->getBot()->sendMessage(
                text: $chunk,
                chat_id: $channelId,
                parse_mode: $parseMode,
            ));
        }
    }

    public function sendMedia(string $channelId, string $path, string $type): void
    {
        $this->safeExecute(function () use ($channelId, $path, $type): void {
            if (in_array(strtolower($type), ['photo', 'image'], true)) {
                $this->getBot()->sendPhoto(photo: $path, chat_id: $channelId);

                return;
            }

            $this->getBot()->sendDocument(document: $path, chat_id: $channelId);
        });
    }

    public function registerWebhook(string $url): void
    {
        $this->safeExecute(fn () => $this->getBot()->setWebhook($url));
    }

    public function handleIncomingMessage(Request $request): IncomingMessage
    {
        $payload = $request->all();
        $message = (array) (data_get($payload, 'message') ?? data_get($payload, 'edited_message') ?? []);
        $date = data_get($message, 'date');
        $channelId = (string) data_get($message, 'chat.id', '');

        return new IncomingMessage(
            platform: 'telegram',
            channelId: $channelId,
            senderId: (string) data_get($message, 'from.id', ''),
            content: (string) (data_get($message, 'text') ?? data_get($message, 'caption', '')),
            mediaUrls: $this->extractMediaIds($message),
            timestamp: is_numeric($date) ? Carbon::createFromTimestampUTC((int) $date) : null,
            rawPayload: $payload,
        );
    }

    public function getName(): string
    {
        return 'telegram';
    }

    public function getCapabilities(): AdapterCapabilities
    {
        return new AdapterCapabilities(
            supportsMedia: true,
            supportsButtons: true,
            supportsMarkdown: true,
            maxMessageLength: 4096,
            supportsEditing: false,
        );
    }

    public function handleCommand(IncomingMessage $message): ?string
    {
        $content = trim($message->content);

        if ($content === '' || ! str_starts_with($content, '/')) {
            return null;
        }

        $command = strtolower(strtok($content, ' ') ?: '');

        return match ($command) {
            '/start' => $this->startResponse(),
            '/new' => $this->newConversationResponse($message),
            '/history' => $this->historyResponse($message),
            '/settings' => $this->settingsResponse(),
            default => 'Unknown command. Try /start, /new, /history, or /settings.',
        };
    }

    public function runPolling(MessageRouter $router): void
    {
        $bot = $this->getBot();
        $bot->setRunningMode('SergiX44\\Nutgram\\RunningMode\\Polling');

        $bot->onMessage(function (object $bot) use ($router): void {
            $incoming = new IncomingMessage(
                platform: 'telegram',
                channelId: (string) ($bot->chatId() ?? ''),
                senderId: (string) ($bot->userId() ?? ''),
                content: trim((string) ($bot->message()?->text ?? '')),
                rawPayload: (array) ($bot->update()?->jsonSerialize() ?? []),
            );

            $commandResponse = $this->handleCommand($incoming);
            if ($commandResponse !== null) {
                $this->sendMessage($incoming->channelId, $commandResponse);

                return;
            }

            $response = $router->route($incoming);
            $this->sendMessage($incoming->channelId, $response);
        });

        $bot->run();
    }

    private function getBot(): object
    {
        if (is_object($this->bot)) {
            return $this->bot;
        }

        $token = (string) config('aegis.messaging.telegram.bot_token', '');
        if ($token === '') {
            throw new RuntimeException('AEGIS_TELEGRAM_BOT_TOKEN is not configured.');
        }

        $nutgramClass = 'SergiX44\\Nutgram\\Nutgram';
        $this->bot = new $nutgramClass($token);

        return $this->bot;
    }

    private function startResponse(): string
    {
        return "*Welcome to Aegis Telegram.*\n\nUse /new to start a fresh conversation, /history to see recent sessions, and /settings for desktop setup.";
    }

    private function newConversationResponse(IncomingMessage $message): string
    {
        $conversation = Conversation::query()->create([
            'title' => sprintf('telegram:%s:%s', $message->channelId, now()->format('YmdHis')),
            'provider' => config('aegis.agent.default_provider'),
            'model' => config('aegis.agent.default_model'),
            'last_message_at' => now(),
        ]);

        MessagingChannel::query()->updateOrCreate(
            [
                'platform' => 'telegram',
                'platform_channel_id' => $message->channelId,
            ],
            [
                'platform_user_id' => $message->senderId,
                'conversation_id' => $conversation->id,
                'active' => true,
            ],
        );

        return "Started a *new conversation* (#{$conversation->id}).";
    }

    private function historyResponse(IncomingMessage $message): string
    {
        $channels = MessagingChannel::query()
            ->with('conversation')
            ->where('platform', 'telegram')
            ->where('platform_user_id', $message->senderId)
            ->latest('updated_at')
            ->limit(5)
            ->get();

        if ($channels->isEmpty()) {
            return 'No recent conversations yet. Use /new to start one.';
        }

        $lines = ['*Recent conversations:*'];

        foreach ($channels as $index => $channel) {
            $conversation = $channel->conversation;
            if (! $conversation instanceof Conversation) {
                continue;
            }

            $lines[] = sprintf(
                '%d. #%d - %s',
                $index + 1,
                $conversation->id,
                $conversation->title,
            );
        }

        return implode("\n", $lines);
    }

    private function settingsResponse(): string
    {
        return '*Settings:* Configure your Telegram token and webhook from Aegis desktop Settings.';
    }

    private function extractMediaIds(array $message): array
    {
        $media = [];

        $document = data_get($message, 'document.file_id');
        if (is_string($document) && $document !== '') {
            $media[] = $document;
        }

        $photos = data_get($message, 'photo', []);
        if (is_array($photos)) {
            foreach ($photos as $photo) {
                $fileId = data_get($photo, 'file_id');
                if (is_string($fileId) && $fileId !== '') {
                    $media[] = $fileId;
                }
            }
        }

        return $media;
    }
}
