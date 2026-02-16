<?php

use App\Enums\MemoryType;
use App\Models\Memory;
use App\Tools\MemoryStoreTool;
use App\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Embeddings;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

beforeEach(function () {
    Embeddings::fake();
    $this->tool = app(MemoryStoreTool::class);
});

it('implements the SDK Tool contract', function () {
    expect($this->tool)->toBeInstanceOf(\Laravel\Ai\Contracts\Tool::class);
});

it('has correct name and description', function () {
    expect($this->tool->name())->toBe('memory_store');
    expect((string) $this->tool->description())->toContain('Store');
});

it('stores a fact memory', function () {
    $request = new Request([
        'type' => 'fact',
        'key' => 'user.name',
        'value' => 'Vijay',
    ]);

    $result = (string) $this->tool->handle($request);

    expect($result)->toContain('Stored fact')
        ->and($result)->toContain('user.name')
        ->and(Memory::query()->where('key', 'user.name')->first()->value)->toBe('Vijay');
});

it('stores a preference memory', function () {
    $request = new Request([
        'type' => 'preference',
        'key' => 'user.preference.theme',
        'value' => 'dark mode',
    ]);

    $result = (string) $this->tool->handle($request);

    expect($result)->toContain('Stored preference')
        ->and(Memory::query()->where('key', 'user.preference.theme')->first()->type)->toBe(MemoryType::Preference);
});

it('stores a note memory', function () {
    $request = new Request([
        'type' => 'note',
        'key' => 'project.aegis.stack',
        'value' => 'Laravel 12, NativePHP, Pest',
    ]);

    $result = (string) $this->tool->handle($request);

    expect($result)->toContain('Stored note');
});

it('rejects empty key or value', function () {
    $request = new Request([
        'type' => 'fact',
        'key' => '',
        'value' => 'test',
    ]);

    $result = (string) $this->tool->handle($request);

    expect($result)->toContain('key and value are required');
});

it('rejects invalid type', function () {
    $request = new Request([
        'type' => 'invalid',
        'key' => 'user.name',
        'value' => 'Test',
    ]);

    $result = (string) $this->tool->handle($request);

    expect($result)->toContain('invalid type');
});

it('deduplicates by key via upsert', function () {
    $request1 = new Request([
        'type' => 'fact',
        'key' => 'user.name',
        'value' => 'Alice',
    ]);

    $request2 = new Request([
        'type' => 'fact',
        'key' => 'user.name',
        'value' => 'Alice Johnson',
    ]);

    $this->tool->handle($request1);
    $this->tool->handle($request2);

    expect(Memory::query()->where('key', 'user.name')->count())->toBe(1)
        ->and(Memory::query()->where('key', 'user.name')->first()->value)->toBe('Alice Johnson');
});

it('is auto-discovered by ToolRegistry', function () {
    $registry = app(ToolRegistry::class);

    expect($registry->get('memory_store'))->toBeInstanceOf(MemoryStoreTool::class);
});
