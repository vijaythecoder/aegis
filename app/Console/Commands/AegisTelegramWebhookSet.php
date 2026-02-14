<?php

namespace App\Console\Commands;

use App\Messaging\Adapters\TelegramAdapter;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as CommandStatus;
use Throwable;

class AegisTelegramWebhookSet extends Command
{
    protected $signature = 'aegis:telegram:webhook:set {url?}';

    protected $description = 'Register Telegram webhook URL';

    public function __construct(
        private readonly TelegramAdapter $adapter,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $url = (string) ($this->argument('url') ?: config('aegis.messaging.telegram.webhook_url', ''));

        if ($url === '') {
            $this->error('Webhook URL missing. Provide {url} or set AEGIS_TELEGRAM_WEBHOOK_URL.');

            return CommandStatus::FAILURE;
        }

        try {
            $this->adapter->registerWebhook($url);
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Failed to register Telegram webhook.');

            return CommandStatus::FAILURE;
        }

        $this->info("Telegram webhook registered: {$url}");

        return CommandStatus::SUCCESS;
    }
}
