<?php

namespace App\Memory;

use App\Enums\MemoryType;
use App\Models\Memory;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class MemoryService
{
    public function store(
        MemoryType $type,
        string $key,
        string $value,
        ?int $conversationId = null,
        float $confidence = 1.0,
    ): Memory {
        $key = trim($key);

        $memory = Memory::query()
            ->where('type', $type)
            ->where('key', $key)
            ->first();

        if ($memory instanceof Memory) {
            $memory->value = $value;
            $memory->confidence = $confidence;

            if ($conversationId !== null) {
                $memory->conversation_id = $conversationId;
            }

            $memory->save();

            return $memory->refresh();
        }

        return Memory::query()->create([
            'type' => $type,
            'key' => $key,
            'value' => $value,
            'conversation_id' => $conversationId,
            'confidence' => $confidence,
            'source' => 'memory_service',
        ]);
    }

    public function search(string $query): Collection
    {
        $query = trim($query);

        if ($query === '') {
            return collect();
        }

        $rows = collect(DB::select(
            'SELECT memories.id FROM memories JOIN memories_fts ON memories_fts.rowid = memories.id WHERE memories_fts MATCH ? ORDER BY bm25(memories_fts)',
            [$query],
        ));

        if ($rows->isEmpty()) {
            return collect();
        }

        $ids = $rows->pluck('id')->map(fn ($id): int => (int) $id)->all();
        $memoriesById = Memory::query()->whereIn('id', $ids)->get()->keyBy('id');

        return collect($ids)
            ->map(fn (int $id): ?Memory => $memoriesById->get($id))
            ->filter()
            ->values();
    }

    public function findByKey(string $key): ?Memory
    {
        return Memory::query()
            ->where('key', trim($key))
            ->orderByDesc('updated_at')
            ->first();
    }

    public function preferences(): Collection
    {
        return Memory::query()
            ->where('type', MemoryType::Preference)
            ->orderBy('key')
            ->get();
    }

    public function facts(): Collection
    {
        return Memory::query()
            ->where('type', MemoryType::Fact)
            ->orderBy('key')
            ->get();
    }
}
