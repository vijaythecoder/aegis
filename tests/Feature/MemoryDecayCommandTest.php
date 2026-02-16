<?php

use App\Enums\MemoryType;
use App\Memory\MemoryService;
use App\Models\Memory;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('decays confidence of stale memories', function () {
    $service = app(MemoryService::class);
    $service->store(MemoryType::Fact, 'user.name', 'Vijay');

    Memory::query()->where('key', 'user.name')->update([
        'updated_at' => now()->subWeeks(2),
        'last_accessed_at' => now()->subWeeks(2),
    ]);

    $affected = $service->decayConfidence(0.1, 1);

    expect($affected)->toBe(1)
        ->and(Memory::query()->where('key', 'user.name')->first()->confidence)->toBeLessThan(1.0);
});

it('does not decay recently accessed memories', function () {
    $service = app(MemoryService::class);
    $service->store(MemoryType::Fact, 'user.name', 'Vijay');

    Memory::query()->where('key', 'user.name')->update([
        'last_accessed_at' => now(),
    ]);

    $affected = $service->decayConfidence(0.1, 1);

    expect($affected)->toBe(0);
});

it('preserves previous value on contradiction update', function () {
    $service = app(MemoryService::class);
    $service->store(MemoryType::Fact, 'user.name', 'Alice');
    $service->store(MemoryType::Fact, 'user.name', 'Alice Johnson');

    $memory = Memory::query()->where('key', 'user.name')->first();

    expect($memory->value)->toBe('Alice Johnson')
        ->and($memory->previous_value)->toBe('Alice');
});

it('does not set previous value when value unchanged', function () {
    $service = app(MemoryService::class);
    $service->store(MemoryType::Fact, 'user.name', 'Alice');
    $service->store(MemoryType::Fact, 'user.name', 'Alice');

    $memory = Memory::query()->where('key', 'user.name')->first();

    expect($memory->previous_value)->toBeNull();
});

it('runs decay command successfully', function () {
    test()->artisan('aegis:memory:decay')
        ->expectsOutputToContain('Decayed')
        ->assertExitCode(0);
});

it('deletes a memory by id', function () {
    $service = app(MemoryService::class);
    $memory = $service->store(MemoryType::Fact, 'user.name', 'Test');

    $result = $service->delete($memory->id);

    expect($result)->toBeTrue()
        ->and(Memory::query()->find($memory->id))->toBeNull();
});
