<?php

namespace App\Console\Commands;

use App\Security\ApiKeyManager;
use App\Security\ProviderConfig;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as CommandStatus;

class AegisKeyTest extends Command
{
    protected $signature = 'aegis:key:test {provider}';

    protected $description = 'Verify key presence and format for a provider';

    public function __construct(
        private readonly ApiKeyManager $apiKeyManager,
        private readonly ProviderConfig $providerConfig,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $provider = strtolower((string) $this->argument('provider'));

        if (! $this->providerConfig->hasProvider($provider)) {
            $this->error("Unsupported provider [{$provider}].");

            return CommandStatus::FAILURE;
        }

        if (! $this->providerConfig->requiresKey($provider)) {
            $this->info("{$this->providerConfig->providerName($provider)} does not require an API key.");

            return CommandStatus::SUCCESS;
        }

        if (! $this->apiKeyManager->has($provider)) {
            $this->error("No key set for {$this->providerConfig->providerName($provider)}.");

            return CommandStatus::FAILURE;
        }

        $key = $this->apiKeyManager->retrieve($provider);

        if ($key === null || ! $this->providerConfig->validate($provider, $key)) {
            $this->error("Stored key for {$this->providerConfig->providerName($provider)} is invalid.");

            return CommandStatus::FAILURE;
        }

        $this->info("Stored key for {$this->providerConfig->providerName($provider)} is valid.");

        return CommandStatus::SUCCESS;
    }
}
