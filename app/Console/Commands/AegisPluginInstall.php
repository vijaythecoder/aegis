<?php

namespace App\Console\Commands;

use App\Plugins\PluginInstaller;
use App\Plugins\PluginManager;
use App\Plugins\PluginVerifier;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Symfony\Component\Console\Command\Command as CommandStatus;

class AegisPluginInstall extends Command
{
    protected $signature = 'aegis:plugin:install {source}';

    protected $description = 'Install a plugin from local path or Git URL';

    public function __construct(
        private readonly PluginInstaller $pluginInstaller,
        private readonly PluginManager $pluginManager,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $source = (string) $this->argument('source');

        try {
            $manifest = $this->pluginInstaller->install($source);
            $this->pluginManager->discover();
            $this->pluginManager->enable($manifest->name);

            $verification = $this->pluginInstaller->lastVerification();

            if (($verification['status'] ?? null) === PluginVerifier::STATUS_UNSIGNED) {
                $this->warn("Plugin [{$manifest->name}] is unsigned. Installed with caution.");
            }

            if (is_string($verification['trust_level'] ?? null) && $verification['trust_level'] !== '') {
                $this->line('Trust level: '.$verification['trust_level']);
            }

            $this->info("Installed plugin [{$manifest->name}] v{$manifest->version}.");

            return CommandStatus::SUCCESS;
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return CommandStatus::FAILURE;
        }
    }
}
