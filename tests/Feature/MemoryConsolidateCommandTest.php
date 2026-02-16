<?php

use App\Enums\MemoryType;
use App\Models\Memory;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('removes duplicate memories keeping the newest', function () {
    // Drop unique constraint to simulate duplicates from race conditions or schema changes
    \Illuminate\Support\Facades\DB::statement('DROP INDEX IF EXISTS memories_type_key_unique');

    \Illuminate\Support\Facades\DB::table('memories')->insert([
        ['type' => 'fact', 'key' => 'user.name', 'value' => 'Old', 'source' => 'test', 'confidence' => 1.0, 'created_at' => now(), 'updated_at' => now()],
        ['type' => 'fact', 'key' => 'user.name', 'value' => 'New', 'source' => 'test', 'confidence' => 1.0, 'created_at' => now(), 'updated_at' => now()],
    ]);

    expect(Memory::query()->where('key', 'user.name')->count())->toBe(2);

    $this->artisan('aegis:memory:consolidate')
        ->expectsOutputToContain('Removed 1 duplicate')
        ->assertExitCode(0);

    expect(Memory::query()->where('key', 'user.name')->count())->toBe(1);
});

it('prunes low-confidence stale memories older than 3 months', function () {
    $memory = Memory::query()->create([
        'type' => MemoryType::Fact,
        'key' => 'old.fact',
        'value' => 'stale info',
        'source' => 'test',
        'confidence' => 0.1,
    ]);

    Memory::query()->where('id', $memory->id)->update([
        'updated_at' => now()->subMonths(4),
    ]);

    $this->artisan('aegis:memory:consolidate')
        ->expectsOutputToContain('Pruned 1')
        ->assertExitCode(0);

    expect(Memory::query()->find($memory->id))->toBeNull();
});

it('does not prune recent low-confidence memories', function () {
    Memory::query()->create([
        'type' => MemoryType::Fact,
        'key' => 'recent.low',
        'value' => 'still fresh',
        'source' => 'test',
        'confidence' => 0.1,
    ]);

    $this->artisan('aegis:memory:consolidate')
        ->expectsOutputToContain('Pruned 0')
        ->assertExitCode(0);

    expect(Memory::query()->where('key', 'recent.low')->count())->toBe(1);
});

it('respects dry-run flag', function () {
    \Illuminate\Support\Facades\DB::statement('DROP INDEX IF EXISTS memories_type_key_unique');

    \Illuminate\Support\Facades\DB::table('memories')->insert([
        ['type' => 'fact', 'key' => 'user.name', 'value' => 'Old', 'source' => 'test', 'confidence' => 1.0, 'created_at' => now(), 'updated_at' => now()],
        ['type' => 'fact', 'key' => 'user.name', 'value' => 'New', 'source' => 'test', 'confidence' => 1.0, 'created_at' => now(), 'updated_at' => now()],
    ]);

    $this->artisan('aegis:memory:consolidate --dry-run')
        ->expectsOutputToContain('DRY RUN')
        ->assertExitCode(0);

    expect(Memory::query()->where('key', 'user.name')->count())->toBe(2);
});

it('runs successfully with no memories', function () {
    $this->artisan('aegis:memory:consolidate')
        ->expectsOutputToContain('Removed 0')
        ->assertExitCode(0);
});
