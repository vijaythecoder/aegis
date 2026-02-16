<?php

namespace App\Console\Commands;

use App\Agent\ProactiveTaskRunner;
use Illuminate\Console\Command;

class RunProactiveTasksCommand extends Command
{
    protected $signature = 'aegis:proactive:run';

    protected $description = 'Run all due proactive tasks';

    public function handle(ProactiveTaskRunner $runner): int
    {
        $count = $runner->runDueTasks();

        $this->info("Ran {$count} proactive task(s).");

        return self::SUCCESS;
    }
}
