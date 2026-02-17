<?php

namespace App\Console\Commands;

use App\Services\ModelPricingService;
use Illuminate\Console\Command;

class RefreshModelPricingCommand extends Command
{
    protected $signature = 'aegis:refresh-pricing';

    protected $description = 'Refresh AI model pricing data from models.dev';

    public function handle(ModelPricingService $pricingService): int
    {
        $this->info('Fetching pricing data from models.dev...');

        if ($pricingService->refresh()) {
            $this->info('Pricing data refreshed successfully.');

            return self::SUCCESS;
        }

        $this->error('Failed to refresh pricing data. Check network connectivity.');

        return self::FAILURE;
    }
}
