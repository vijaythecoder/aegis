<?php

namespace App\Console\Commands;

use App\Marketplace\MarketplaceService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Symfony\Component\Console\Command\Command as CommandStatus;

class AegisMarketplaceUpdate extends Command
{
    protected $signature = 'aegis:marketplace:update';

    protected $description = 'Check installed plugins for marketplace updates';

    public function __construct(private readonly MarketplaceService $marketplaceService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $updates = $this->marketplaceService->checkUpdates();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return CommandStatus::FAILURE;
        }

        if ($updates === []) {
            $this->info('All installed plugins are up to date.');

            return CommandStatus::SUCCESS;
        }

        $rows = [];

        foreach ($updates as $update) {
            $rows[] = [$update['name'], $update['installed_version'], $update['latest_version']];
        }

        $this->table(['Name', 'Installed', 'Latest'], $rows);

        return CommandStatus::SUCCESS;
    }
}
