<?php

namespace App\Console\Commands;

use App\Models\Memory;
use Illuminate\Console\Command;
use Symfony\Component\Console\Command\Command as CommandStatus;

class AegisMemoryList extends Command
{
    protected $signature = 'aegis:memory:list';

    protected $description = 'List memories grouped by type';

    public function handle(): int
    {
        $grouped = Memory::query()
            ->orderBy('type')
            ->orderBy('key')
            ->get()
            ->groupBy(fn (Memory $memory): string => $memory->type->value);

        if ($grouped->isEmpty()) {
            $this->warn('No memories found.');

            return CommandStatus::SUCCESS;
        }

        foreach ($grouped as $type => $memories) {
            $this->line($type);

            $rows = $memories->map(fn (Memory $memory): array => [
                $memory->id,
                $memory->key,
                $memory->value,
                $memory->conversation_id,
                $memory->confidence,
            ])->all();

            $this->table(['ID', 'Key', 'Value', 'Conversation', 'Confidence'], $rows);
        }

        return CommandStatus::SUCCESS;
    }
}
