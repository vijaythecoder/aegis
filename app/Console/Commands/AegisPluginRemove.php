<?php

namespace App\Console\Commands;

use App\Plugins\PluginInstaller;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as CommandStatus;

class AegisPluginRemove extends Command
{
    protected $signature = 'aegis:plugin:remove {name}';

    protected $description = 'Remove an installed plugin by name';

    public function __construct(private readonly PluginInstaller $pluginInstaller)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $name = (string) $this->argument('name');

        if (! $this->pluginInstaller->remove($name)) {
            $this->error("Plugin [{$name}] is not installed.");

            return CommandStatus::FAILURE;
        }

        $this->info("Removed plugin [{$name}].");

        return CommandStatus::SUCCESS;
    }
}
