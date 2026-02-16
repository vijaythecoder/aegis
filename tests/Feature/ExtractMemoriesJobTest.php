<?php

use App\Agent\MemoryExtractorAgent;
use App\Jobs\ExtractMemoriesJob;
use App\Models\Conversation;
use App\Models\Memory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;

uses(RefreshDatabase::class);

it('extracts facts from conversation via structured agent and stores them', function () {
    Embeddings::fake();
    MemoryExtractorAgent::fake([
        ['memories' => [
            ['type' => 'fact', 'key' => 'user.name', 'value' => 'Vijay'],
            ['type' => 'preference', 'key' => 'user.preference.editor', 'value' => 'VS Code'],
        ]],
    ]);

    $conversation = Conversation::factory()->create();

    $job = new ExtractMemoriesJob(
        'My name is Vijay and I use VS Code',
        'Nice to meet you Vijay!',
        $conversation->id,
    );

    $job->handle(
        app(\App\Memory\MemoryService::class),
        app(\App\Memory\EmbeddingService::class),
        app(\App\Memory\VectorStore::class),
        app(\App\Memory\UserProfileService::class),
    );

    expect(Memory::query()->where('key', 'user.name')->first()->value)->toBe('Vijay')
        ->and(Memory::query()->where('key', 'user.preference.editor')->first()->value)->toBe('VS Code');

    MemoryExtractorAgent::assertPrompted(fn ($prompt) => str_contains($prompt->prompt, 'Vijay'));
});

it('handles empty extraction gracefully', function () {
    MemoryExtractorAgent::fake([
        ['memories' => []],
    ]);

    $job = new ExtractMemoriesJob('hey', 'hello!');

    $job->handle(
        app(\App\Memory\MemoryService::class),
        app(\App\Memory\EmbeddingService::class),
        app(\App\Memory\VectorStore::class),
        app(\App\Memory\UserProfileService::class),
    );

    expect(Memory::query()->count())->toBe(0);
});

it('handles agent failure gracefully', function () {
    MemoryExtractorAgent::fake(fn () => throw new RuntimeException('API error'));

    $job = new ExtractMemoriesJob('test message', 'test response');

    $job->handle(
        app(\App\Memory\MemoryService::class),
        app(\App\Memory\EmbeddingService::class),
        app(\App\Memory\VectorStore::class),
        app(\App\Memory\UserProfileService::class),
    );

    expect(Memory::query()->count())->toBe(0);
});

it('skips extraction when fact_extraction is disabled', function () {
    config(['aegis.memory.fact_extraction' => false]);

    MemoryExtractorAgent::fake([
        ['memories' => [
            ['type' => 'fact', 'key' => 'user.name', 'value' => 'Test'],
        ]],
    ]);

    $job = new ExtractMemoriesJob('My name is Test', 'Hello Test!');

    $job->handle(
        app(\App\Memory\MemoryService::class),
        app(\App\Memory\EmbeddingService::class),
        app(\App\Memory\VectorStore::class),
        app(\App\Memory\UserProfileService::class),
    );

    expect(Memory::query()->count())->toBe(0);

    MemoryExtractorAgent::assertNeverPrompted();
});

it('can be dispatched to the queue', function () {
    Queue::fake();

    ExtractMemoriesJob::dispatch('test user message', 'test assistant response', 1);

    Queue::assertPushed(ExtractMemoriesJob::class);
});

it('filters out malformed items from structured response', function () {
    Embeddings::fake();
    MemoryExtractorAgent::fake([
        ['memories' => [
            ['type' => 'fact', 'key' => 'user.name', 'value' => 'Valid'],
            ['type' => 'invalid_type', 'key' => '', 'value' => ''],
            ['missing_keys' => true],
        ]],
    ]);

    $conversation = Conversation::factory()->create();

    $job = new ExtractMemoriesJob('My name is Valid', 'Hello!', $conversation->id);

    $job->handle(
        app(\App\Memory\MemoryService::class),
        app(\App\Memory\EmbeddingService::class),
        app(\App\Memory\VectorStore::class),
        app(\App\Memory\UserProfileService::class),
    );

    expect(Memory::query()->count())->toBe(1)
        ->and(Memory::query()->first()->value)->toBe('Valid');
});
