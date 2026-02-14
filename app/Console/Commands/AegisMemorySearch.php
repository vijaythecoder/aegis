<?php

namespace App\Console\Commands;

use App\Memory\MemoryService;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as CommandStatus;

class AegisMemorySearch extends Command
{
    protected $signature = 'aegis:memory:search {query}';

    protected $description = 'Search memories using FTS';

    public function __construct(private readonly MemoryService $memoryService)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        $query = (string) $this->argument('query');
        $results = $this->memoryService->search($query);

        if ($results->isEmpty()) {
            $this->warn('No matching memories found.');

            return CommandStatus::SUCCESS;
        }

        $rows = $results->map(fn ($memory): array => [
            $memory->id,
            $memory->type->value,
            $memory->key,
            $memory->value,
            $memory->confidence,
        ])->all();

        $this->table(['ID', 'Type', 'Key', 'Value', 'Confidence'], $rows);

        return CommandStatus::SUCCESS;
    }
}
