<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Command\Command as CommandStatus;
use Throwable;

class AegisVacuum extends Command
{
    protected $signature = 'aegis:vacuum';

    protected $description = 'Run VACUUM on SQLite database';

    public function handle(): int
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->info('Skipping VACUUM: current driver is not SQLite.');

            return CommandStatus::SUCCESS;
        }

        try {
            DB::statement('VACUUM');
        } catch (Throwable $exception) {
            $this->error('VACUUM failed: '.$exception->getMessage());

            return CommandStatus::FAILURE;
        }

        $this->info('VACUUM completed successfully.');

        return CommandStatus::SUCCESS;
    }
}
