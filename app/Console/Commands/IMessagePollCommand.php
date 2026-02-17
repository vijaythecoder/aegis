<?php

namespace App\Console\Commands;

use App\Console\Concerns\UsesNativephpDatabase;
use App\Messaging\Adapters\IMessageAdapter;
use App\Messaging\MessageRouter;
use App\Models\Setting;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Console\Command\Command as CommandStatus;

class IMessagePollCommand extends Command
{
    use UsesNativephpDatabase;

    protected $signature = 'aegis:imessage:poll {--interval=5 : Polling interval in seconds}';

    protected $description = 'Poll macOS Messages (chat.db) for new iMessages and route them through the agent';

    public function handle(): int
    {
        if (PHP_OS !== 'Darwin') {
            $this->error('iMessage polling is only available on macOS.');

            return CommandStatus::FAILURE;
        }

        if (! $this->useNativephpDatabase()) {
            $this->warn('NativePHP database not found. Using default database.');
        }

        if (! config('aegis.messaging.imessage.enabled')) {
            $this->error('iMessage integration is disabled. Enable it in Settings > Messaging.');

            return CommandStatus::FAILURE;
        }

        $lockPath = storage_path('framework/imessage-poll.lock');
        $lockHandle = fopen($lockPath, 'c+');

        if ($lockHandle === false || ! flock($lockHandle, LOCK_EX | LOCK_NB)) {
            $this->warn('Another aegis:imessage:poll process is already running. Exiting.');

            return CommandStatus::SUCCESS;
        }

        fwrite($lockHandle, (string) getmypid());
        fflush($lockHandle);

        register_shutdown_function(function () use ($lockHandle, $lockPath): void {
            flock($lockHandle, LOCK_UN);
            fclose($lockHandle);
            @unlink($lockPath);
        });

        $adapter = new IMessageAdapter;

        if (! $adapter->isChatDbAccessible()) {
            $this->error(sprintf(
                'Cannot read Messages database at: %s',
                $adapter->getChatDbPath(),
            ));
            $this->line('');
            $this->line('Grant Full Disk Access to Aegis:');
            $this->line('  System Settings → Privacy & Security → Full Disk Access');

            return CommandStatus::FAILURE;
        }

        $interval = max(1, (int) $this->option('interval'));
        $lastRowId = $adapter->getMaxRowId();

        $rawContacts = Setting::query()
            ->where('group', 'messaging')
            ->where('key', 'imessage_chat_id')
            ->value('value');

        $filterContacts = is_string($rawContacts)
            ? array_values(array_filter(array_map('trim', explode(',', $rawContacts)), fn (string $c): bool => $c !== ''))
            : [];

        if ($filterContacts === []) {
            $this->error('No contacts configured. Add phone numbers or emails in Settings > Messaging.');

            return CommandStatus::FAILURE;
        }

        $this->info('Watching for messages from: '.implode(', ', $filterContacts));
        $this->info("Polling iMessage chat.db every {$interval}s (starting from ROWID {$lastRowId}).");
        $this->info('Press Ctrl+C to stop.');

        $router = app(MessageRouter::class);
        $router->registerAdapter('imessage', $adapter);

        while (true) {
            try {
                $lastRowId = $this->poll($adapter, $router, $lastRowId, $filterContacts);
            } catch (\Throwable $e) {
                Log::warning('imessage.poll.error', ['error' => $e->getMessage()]);
                $this->warn("Poll error: {$e->getMessage()}");
            }

            sleep($interval);
        }

        return CommandStatus::SUCCESS; // @phpstan-ignore deadCode.unreachable
    }

    /**
     * @param  list<string>  $filterContacts
     */
    private function poll(IMessageAdapter $adapter, MessageRouter $router, int $lastRowId, array $filterContacts): int
    {
        $result = $adapter->pollChatDatabase($lastRowId, $filterContacts);
        $messages = $result['messages'];
        $newMaxRowId = $result['maxRowId'];

        foreach ($messages as $incoming) {
            $this->line(sprintf(
                '<info>[%s]</info> %s',
                $incoming->channelId,
                mb_substr($incoming->content, 0, 100),
            ));

            try {
                $response = $router->route($incoming);
                $adapter->sendMessage($incoming->channelId, $response->text);

                $this->line(sprintf(
                    '<comment>  → Replied (%d chars)</comment>',
                    mb_strlen($response->text),
                ));
            } catch (\Throwable $e) {
                Log::warning('imessage.poll.route_error', [
                    'channel' => $incoming->channelId,
                    'sender' => $incoming->senderId,
                    'error' => $e->getMessage(),
                ]);
                $this->warn("  → Error routing message: {$e->getMessage()}");

                $adapter->sendMessage(
                    $incoming->channelId,
                    'Sorry, something went wrong processing your message.',
                );
            }
        }

        return $newMaxRowId;
    }
}
