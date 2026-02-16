<?php

namespace App\Console\Commands;

use App\Models\Memory;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class MemoryConsolidateCommand extends Command
{
    protected $signature = 'aegis:memory:consolidate
        {--dry-run : Show what would be consolidated without making changes}';

    protected $description = 'Consolidate memories by removing exact duplicates and pruning low-confidence stale entries';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $duplicatesRemoved = $this->removeDuplicates($dryRun);
        $pruned = $this->pruneLowConfidence($dryRun);

        $prefix = $dryRun ? '[DRY RUN] Would have' : '';

        $this->info("{$prefix} Removed {$duplicatesRemoved} duplicate memories.");
        $this->info("{$prefix} Pruned {$pruned} low-confidence stale memories.");

        return self::SUCCESS;
    }

    private function removeDuplicates(bool $dryRun): int
    {
        $duplicateGroups = Memory::query()
            ->select('type', 'key', DB::raw('COUNT(*) as count'), DB::raw('MAX(id) as keep_id'))
            ->groupBy('type', 'key')
            ->having(DB::raw('COUNT(*)'), '>', 1)
            ->get();

        $totalRemoved = 0;

        foreach ($duplicateGroups as $group) {
            $toRemove = Memory::query()
                ->where('type', $group->type)
                ->where('key', $group->key)
                ->where('id', '!=', $group->keep_id)
                ->count();

            if (! $dryRun) {
                Memory::query()
                    ->where('type', $group->type)
                    ->where('key', $group->key)
                    ->where('id', '!=', $group->keep_id)
                    ->delete();
            }

            $totalRemoved += $toRemove;
        }

        return $totalRemoved;
    }

    private function pruneLowConfidence(bool $dryRun): int
    {
        $threshold = now()->subMonths(3);

        $query = Memory::query()
            ->where('confidence', '<=', 0.1)
            ->where('updated_at', '<', $threshold);

        $count = $query->count();

        if (! $dryRun && $count > 0) {
            $query->delete();
        }

        return $count;
    }
}
