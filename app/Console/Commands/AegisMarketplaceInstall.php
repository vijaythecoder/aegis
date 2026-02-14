<?php

namespace App\Console\Commands;

use App\Marketplace\MarketplaceService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Symfony\Component\Console\Command\Command as CommandStatus;

class AegisMarketplaceInstall extends Command
{
    protected $signature = 'aegis:marketplace:install {name}';

    protected $description = 'Install a plugin from the marketplace';

    public function __construct(private readonly MarketplaceService $marketplaceService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $name = (string) $this->argument('name');

        try {
            $manifest = $this->marketplaceService->install($name);
            $this->info("Installed marketplace plugin [{$manifest->name}] v{$manifest->version}.");

            return CommandStatus::SUCCESS;
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return CommandStatus::FAILURE;
        }
    }
}
