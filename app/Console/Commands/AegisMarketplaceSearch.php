<?php

namespace App\Console\Commands;

use App\Marketplace\MarketplaceService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Symfony\Component\Console\Command\Command as CommandStatus;

class AegisMarketplaceSearch extends Command
{
    protected $signature = 'aegis:marketplace:search {query}';

    protected $description = 'Search marketplace plugins';

    public function __construct(private readonly MarketplaceService $marketplaceService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        try {
            $plugins = $this->marketplaceService->search((string) $this->argument('query'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return CommandStatus::FAILURE;
        }

        $rows = [];

        foreach ($plugins as $plugin) {
            $rows[] = [
                $plugin->name,
                $plugin->version,
                $plugin->author,
                $plugin->trustTierLabel(),
                (string) $plugin->downloads,
            ];
        }

        $this->table(['Name', 'Version', 'Author', 'Trust', 'Downloads'], $rows);

        return CommandStatus::SUCCESS;
    }
}
