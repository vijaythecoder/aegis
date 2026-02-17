<?php

namespace App\Console\Commands;

use App\Services\OpenRouterModelService;
use Illuminate\Console\Command;

class RefreshOpenRouterModelsCommand extends Command
{
    protected $signature = 'aegis:refresh-models';

    protected $description = 'Refresh the cached list of available OpenRouter models';

    public function handle(OpenRouterModelService $modelService): int
    {
        $this->info('Fetching available models from OpenRouter...');

        if ($modelService->refresh()) {
            $count = count($modelService->getModelIds());
            $this->info("Models refreshed successfully. {$count} models available.");

            return self::SUCCESS;
        }

        $this->error('Failed to refresh models. Check network connectivity.');

        return self::FAILURE;
    }
}
