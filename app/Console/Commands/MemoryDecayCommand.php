<?php

namespace App\Console\Commands;

use App\Memory\MemoryService;
use Illuminate\Console\Command;

class MemoryDecayCommand extends Command
{
    protected $signature = 'aegis:memory:decay
        {--decay=0.01 : Confidence reduction per cycle}
        {--stale=1 : Weeks without access before decay applies}';

    protected $description = 'Decay confidence of stale memories that have not been accessed recently';

    public function handle(MemoryService $memoryService): int
    {
        $decay = (float) $this->option('decay');
        $staleWeeks = (int) $this->option('stale');

        $affected = $memoryService->decayConfidence($decay, $staleWeeks);

        $this->info("Decayed {$affected} stale memories by {$decay}.");

        return self::SUCCESS;
    }
}
