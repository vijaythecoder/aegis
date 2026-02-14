<?php

namespace App\Console\Commands;

use App\Plugins\PluginManager;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as CommandStatus;

class AegisPluginList extends Command
{
    protected $signature = 'aegis:plugin:list';

    protected $description = 'List installed Aegis plugins';

    public function __construct(private readonly PluginManager $pluginManager)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $rows = [];

        foreach ($this->pluginManager->installed() as $plugin) {
            $rows[] = [
                $plugin->name,
                $plugin->version,
                $plugin->description,
                $this->pluginManager->isEnabled($plugin->name) ? 'enabled' : 'disabled',
            ];
        }

        $this->table(['Name', 'Version', 'Description', 'Status'], $rows);

        return CommandStatus::SUCCESS;
    }
}
