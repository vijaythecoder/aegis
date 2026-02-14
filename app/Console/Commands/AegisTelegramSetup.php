<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as CommandStatus;

class AegisTelegramSetup extends Command
{
    protected $signature = 'aegis:telegram:setup';

    protected $description = 'Guide Telegram bot setup for Aegis';

    public function handle(): int
    {
        $this->info('Telegram bot setup checklist:');
        $this->newLine();

        $this->line('1) Open @BotFather in Telegram and run /newbot.');
        $this->line('2) Copy the API token and set AEGIS_TELEGRAM_BOT_TOKEN in your .env file.');
        $this->line('3) Set AEGIS_TELEGRAM_WEBHOOK_URL to your public webhook endpoint.');
        $this->line('4) Run `php artisan aegis:telegram:webhook:set` to register the webhook.');
        $this->line('5) For local dev, set AEGIS_TELEGRAM_MODE=polling and run `php artisan aegis:telegram:poll`.');
        $this->newLine();

        $tokenConfigured = (string) config('aegis.messaging.telegram.bot_token', '') !== '';
        $webhookConfigured = (string) config('aegis.messaging.telegram.webhook_url', '') !== '';

        $this->table(
            ['Setting', 'Status'],
            [
                ['AEGIS_TELEGRAM_BOT_TOKEN', $tokenConfigured ? 'configured' : 'missing'],
                ['AEGIS_TELEGRAM_WEBHOOK_URL', $webhookConfigured ? 'configured' : 'missing'],
            ],
        );

        return CommandStatus::SUCCESS;
    }
}
