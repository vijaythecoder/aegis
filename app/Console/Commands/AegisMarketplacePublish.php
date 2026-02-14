<?php

namespace App\Console\Commands;

use App\Marketplace\MarketplaceService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Symfony\Component\Console\Command\Command as CommandStatus;

class AegisMarketplacePublish extends Command
{
    protected $signature = 'aegis:marketplace:publish {path}';

    protected $description = 'Publish a local plugin manifest to the marketplace';

    public function __construct(private readonly MarketplaceService $marketplaceService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $path = (string) $this->argument('path');

        try {
            $result = $this->marketplaceService->publish($path);
            $this->info('Plugin published to marketplace.');

            if ($result !== []) {
                $this->line(json_encode($result, JSON_UNESCAPED_SLASHES));
            }

            return CommandStatus::SUCCESS;
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return CommandStatus::FAILURE;
        }
    }
}
