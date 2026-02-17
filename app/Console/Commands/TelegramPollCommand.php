<?php

namespace App\Console\Commands;

use App\Console\Concerns\UsesNativephpDatabase;
use App\Messaging\Adapters\TelegramAdapter;
use App\Messaging\IncomingMessage;
use App\Messaging\MessageRouter;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Command\Command as CommandStatus;

class TelegramPollCommand extends Command
{
    use UsesNativephpDatabase;

    protected $signature = 'telegram:poll {--interval=2 : Polling interval in seconds}';

    protected $description = 'Poll Telegram for new messages using getUpdates API';

    private int $offset = 0;

    private array $processedUpdateIds = [];

    public function handle(): int
    {
        if (! $this->useNativephpDatabase()) {
            $this->warn('NativePHP database not found. Using default database.');
        }

        $lockPath = storage_path('framework/telegram-poll.lock');
        $lockHandle = fopen($lockPath, 'c+');

        if ($lockHandle === false || ! flock($lockHandle, LOCK_EX | LOCK_NB)) {
            $this->warn('Another telegram:poll process is already running. Exiting.');

            return CommandStatus::SUCCESS;
        }

        fwrite($lockHandle, (string) getmypid());
        fflush($lockHandle);

        register_shutdown_function(function () use ($lockHandle, $lockPath): void {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
            @unlink($lockPath);
        });

        $token = (string) config('aegis.messaging.telegram.bot_token', '');

        if ($token === '') {
            $this->error('Telegram bot token not configured. Set it in Settings > Messaging.');

            return CommandStatus::FAILURE;
        }

        $interval = max(1, (int) $this->option('interval'));
        $baseUrl = "https://api.telegram.org/bot{$token}";

        $this->info("Polling Telegram every {$interval}s. Press Ctrl+C to stop.");

        $me = Http::get("{$baseUrl}/getMe")->json();
        $botName = data_get($me, 'result.username', 'unknown');
        $this->info("Connected as @{$botName}");

        $this->flushPendingUpdates($baseUrl);

        while (true) {
            try {
                $this->poll($baseUrl);
            } catch (\Throwable $e) {
                Log::warning('telegram.poll.error', ['error' => $e->getMessage()]);
                $this->warn("Poll error: {$e->getMessage()}");
            }

            sleep($interval);
        }

        return CommandStatus::SUCCESS; // @phpstan-ignore deadCode.unreachable
    }

    private function poll(string $baseUrl): void
    {
        $response = Http::timeout(35)->get("{$baseUrl}/getUpdates", [
            'offset' => $this->offset,
            'timeout' => 30,
            'allowed_updates' => ['message', 'edited_message'],
        ]);

        if (! $response->successful()) {
            $this->warn("Telegram API returned {$response->status()}");

            return;
        }

        $updates = $response->json('result', []);

        if (! is_array($updates) || $updates === []) {
            return;
        }

        $router = app(MessageRouter::class);
        $adapter = $router->getAdapter('telegram');

        if (! $adapter instanceof TelegramAdapter) {
            $this->error('Telegram adapter not registered.');

            return;
        }

        foreach ($updates as $update) {
            $updateId = (int) data_get($update, 'update_id', 0);
            $this->offset = $updateId + 1;

            if (isset($this->processedUpdateIds[$updateId])) {
                continue;
            }

            $this->processedUpdateIds[$updateId] = true;

            if (count($this->processedUpdateIds) > 500) {
                $this->processedUpdateIds = array_slice($this->processedUpdateIds, -200, null, true);
            }

            $message = (array) (data_get($update, 'message') ?? data_get($update, 'edited_message') ?? []);
            $chatId = (string) data_get($message, 'chat.id', '');
            $text = (string) (data_get($message, 'text') ?? data_get($message, 'caption', ''));

            if ($chatId === '' || trim($text) === '') {
                continue;
            }

            $incoming = new IncomingMessage(
                platform: 'telegram',
                channelId: $chatId,
                senderId: (string) data_get($message, 'from.id', ''),
                content: $text,
                rawPayload: $update,
                timestamp: is_numeric(data_get($message, 'date'))
                    ? Carbon::createFromTimestampUTC((int) data_get($message, 'date'))
                    : null,
            );

            $this->line("<info>[{$chatId}]</info> {$text}");

            $commandResponse = $adapter->handleCommand($incoming);

            if ($commandResponse !== null) {
                $this->sendReply($baseUrl, $chatId, $commandResponse);

                continue;
            }

            try {
                $reply = $router->route($incoming);
                $this->sendReply($baseUrl, $chatId, $reply->text);

                foreach ($reply->attachments as $attachment) {
                    $this->sendPhoto($baseUrl, $chatId, $attachment['path']);
                }
            } catch (\Throwable $e) {
                Log::warning('telegram.poll.route_error', [
                    'chat_id' => $chatId,
                    'error' => $e->getMessage(),
                ]);
                $this->sendReply($baseUrl, $chatId, 'Sorry, something went wrong processing your message.');
            }
        }
    }

    private function flushPendingUpdates(string $baseUrl): void
    {
        $response = Http::timeout(5)->get("{$baseUrl}/getUpdates", ['offset' => -1]);

        if (! $response->successful()) {
            return;
        }

        $updates = $response->json('result', []);

        if (is_array($updates) && $updates !== []) {
            $lastId = (int) data_get(end($updates), 'update_id', 0);
            $this->offset = $lastId + 1;

            Http::timeout(5)->get("{$baseUrl}/getUpdates", ['offset' => $this->offset]);
            $this->info("Flushed stale updates. Offset set to {$this->offset}.");
        }
    }

    private function sendReply(string $baseUrl, string $chatId, string $text): void
    {
        foreach ($this->splitLongMessage($text, 4096) as $chunk) {
            Http::post("{$baseUrl}/sendMessage", [
                'chat_id' => $chatId,
                'text' => $chunk,
                'parse_mode' => 'Markdown',
            ]);
        }
    }

    private function sendPhoto(string $baseUrl, string $chatId, string $filePath): void
    {
        if (! file_exists($filePath)) {
            Log::warning('telegram.poll.photo_not_found', ['path' => $filePath]);

            return;
        }

        Http::attach('photo', (string) file_get_contents($filePath), basename($filePath))
            ->post("{$baseUrl}/sendPhoto", [
                'chat_id' => $chatId,
            ]);
    }

    /**
     * @return string[]
     */
    private function splitLongMessage(string $text, int $maxLength): array
    {
        if (mb_strlen($text) <= $maxLength) {
            return [$text];
        }

        $chunks = [];
        $remaining = $text;

        while ($remaining !== '') {
            if (mb_strlen($remaining) <= $maxLength) {
                $chunks[] = $remaining;
                break;
            }

            $candidate = mb_substr($remaining, 0, $maxLength);
            $breakAt = mb_strrpos($candidate, "\n") ?: mb_strrpos($candidate, ' ') ?: $maxLength;
            $chunks[] = trim(mb_substr($remaining, 0, $breakAt));
            $remaining = ltrim(mb_substr($remaining, $breakAt));
        }

        return array_values(array_filter($chunks, fn (string $c): bool => $c !== ''));
    }
}
