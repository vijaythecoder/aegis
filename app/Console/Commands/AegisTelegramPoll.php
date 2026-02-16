<?php

namespace App\Console\Commands;

use App\Console\Concerns\UsesNativephpDatabase;
use App\Messaging\Adapters\TelegramAdapter;
use App\Messaging\MessageRouter;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as CommandStatus;
use Throwable;

class AegisTelegramPoll extends Command
{
    use UsesNativephpDatabase;

    protected $signature = 'aegis:telegram:poll';

    protected $description = 'Run Telegram bot in long polling mode';

    public function __construct(
        private readonly TelegramAdapter $adapter,
        private readonly MessageRouter $router,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->useNativephpDatabase()) {
            $this->warn('NativePHP database not found. Using default database.');
        }

        $this->info('Starting Telegram polling mode...');

        try {
            $this->adapter->runPolling($this->router);
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Telegram polling crashed. Check token/network and retry.');

            return CommandStatus::FAILURE;
        }

        return CommandStatus::SUCCESS;
    }
}
