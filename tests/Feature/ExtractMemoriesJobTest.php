<?php

use App\Jobs\ExtractMemoriesJob;
use App\Models\Conversation;
use App\Models\Memory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Laravel\Ai\Embeddings;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;

uses(RefreshDatabase::class);

it('extracts facts from conversation via LLM and stores them', function () {
    Embeddings::fake();
    Prism::fake([
        TextResponseFake::make()->withText('[{"type":"fact","key":"user.name","value":"Vijay"},{"type":"preference","key":"user.preference.editor","value":"VS Code"}]'),
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
});

it('handles empty extraction gracefully', function () {
    Prism::fake([
        TextResponseFake::make()->withText('[]'),
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

it('handles malformed LLM response gracefully', function () {
    Prism::fake([
        TextResponseFake::make()->withText('not valid json at all'),
    ]);

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

    Prism::fake([
        TextResponseFake::make()->withText('[{"type":"fact","key":"user.name","value":"Test"}]'),
    ]);

    $job = new ExtractMemoriesJob('My name is Test', 'Hello Test!');

    $job->handle(
        app(\App\Memory\MemoryService::class),
        app(\App\Memory\EmbeddingService::class),
        app(\App\Memory\VectorStore::class),
        app(\App\Memory\UserProfileService::class),
    );

    expect(Memory::query()->count())->toBe(0);
});

it('can be dispatched to the queue', function () {
    Queue::fake();

    ExtractMemoriesJob::dispatch('test user message', 'test assistant response', 1);

    Queue::assertPushed(ExtractMemoriesJob::class);
});
