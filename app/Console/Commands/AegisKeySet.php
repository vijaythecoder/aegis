<?php

namespace App\Console\Commands;

use App\Models\Setting;
use App\Security\ApiKeyManager;
use App\Security\ProviderConfig;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as CommandStatus;

class AegisKeySet extends Command
{
    protected $signature = 'aegis:key:set {provider} {--key=}';

    protected $description = 'Set and encrypt an API key for an Aegis provider';

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

        $key = (string) ($this->option('key') ?: $this->secret('Enter API key'));

        if ($key === '') {
            $this->error('API key cannot be empty.');

            return CommandStatus::FAILURE;
        }

        if (! $this->providerConfig->validate($provider, $key)) {
            $this->error("Invalid API key format for {$this->providerConfig->providerName($provider)}.");

            return CommandStatus::FAILURE;
        }

        $this->apiKeyManager->store($provider, $key);

        $rawValue = Setting::query()
            ->where('group', 'credentials')
            ->where('key', $provider.'_api_key')
            ->value('value');

        if ($rawValue === $key || ($rawValue !== null && str_contains($rawValue, $key))) {
            $this->error('Security check failed: plaintext key detected in database.');

            return CommandStatus::FAILURE;
        }

        $this->info("API key for {$this->providerConfig->providerName($provider)} stored encrypted.");

        return CommandStatus::SUCCESS;
    }
}
