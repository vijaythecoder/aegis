<?php

use App\Enums\MemoryType;
use App\Enums\MessageRole;
use App\Memory\ConversationService;
use App\Memory\FactExtractor;
use App\Memory\MemoryService;
use App\Memory\MessageService;
use App\Models\Conversation;
use App\Models\Memory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;

uses(RefreshDatabase::class);

it('creates and finds a conversation', function () {
    $service = app(ConversationService::class);

    $conversation = $service->create('Roadmap chat', 'claude-sonnet-4', 'anthropic');

    $found = $service->find($conversation->id);

    expect($found)->not->toBeNull()
        ->and($found?->title)->toBe('Roadmap chat')
        ->and($found?->model)->toBe('claude-sonnet-4')
        ->and($found?->provider)->toBe('anthropic');
});

it('paginates conversations newest first', function () {
    $service = app(ConversationService::class);

    Conversation::factory()->count(25)->create();

    $page = $service->list(20);

    expect($page->total())->toBe(25)
        ->and($page->perPage())->toBe(20)
        ->and($page->items())->toHaveCount(20);
});

it('archives updates title and deletes conversations', function () {
    $service = app(ConversationService::class);
    $conversation = Conversation::factory()->create(['is_archived' => false, 'title' => 'Old']);

    $service->archive($conversation->id);
    $service->updateTitle($conversation->id, 'New Title');

    expect($conversation->fresh()->is_archived)->toBeTrue()
        ->and($conversation->fresh()->title)->toBe('New Title');

    $service->delete($conversation->id);

    expect(Conversation::query()->find($conversation->id))->toBeNull();
});

it('generates title for untitled conversation via ConversationService', function () {
    $conversationService = app(ConversationService::class);
    $conversation = $conversationService->create('');
    $content = 'I need help drafting a multi-quarter product roadmap for B2B teams.';

    Prism::fake([
        TextResponseFake::make()->withText('B2B Product Roadmap Help'),
    ]);

    $conversationService->generateTitle($conversation->id, $content);

    expect($conversation->fresh()->title)->toBe('B2B Product Roadmap Help');
});

it('falls back to truncated message when LLM unavailable', function () {
    $conversationService = app(ConversationService::class);
    $conversation = $conversationService->create('');
    $content = 'I need help drafting a multi-quarter product roadmap for B2B teams.';

    Prism::fake([
        TextResponseFake::make()->withText(''),
    ]);

    $conversationService->generateTitle($conversation->id, $content);

    expect($conversation->fresh()->title)->toBe(substr($content, 0, 50));
});

it('stores and loads message history by role and computes token count', function () {
    $conversation = Conversation::factory()->create();
    $service = app(MessageService::class);

    $service->store($conversation->id, MessageRole::User, 'First user input');
    $service->store($conversation->id, MessageRole::Assistant, 'First assistant response');
    $service->store($conversation->id, MessageRole::Tool, 'Tool result', ['ok' => true]);

    $history = $service->loadHistory($conversation->id);

    expect($history)->toHaveCount(3)
        ->and($history[0]->role)->toBe(MessageRole::User)
        ->and($history[1]->role)->toBe(MessageRole::Assistant)
        ->and($history[2]->role)->toBe(MessageRole::Tool)
        ->and($service->tokenCount($conversation->id))->toBeGreaterThan(0);
});

it('loads only limited message history from newest messages', function () {
    $conversation = Conversation::factory()->create();
    $service = app(MessageService::class);

    $service->store($conversation->id, MessageRole::User, 'one');
    $service->store($conversation->id, MessageRole::User, 'two');
    $service->store($conversation->id, MessageRole::User, 'three');

    $history = $service->loadHistory($conversation->id, 2);

    expect($history)->toHaveCount(2)
        ->and($history[0]->content)->toBe('two')
        ->and($history[1]->content)->toBe('three');
});

it('stores memories and deduplicates by type and key', function () {
    $service = app(MemoryService::class);

    $first = $service->store(MemoryType::Fact, 'user.name', 'Alice');
    $second = $service->store(MemoryType::Fact, 'user.name', 'Alice Johnson');

    expect($first->id)->toBe($second->id)
        ->and(Memory::query()->where('type', MemoryType::Fact)->where('key', 'user.name')->count())->toBe(1)
        ->and($second->fresh()->value)->toBe('Alice Johnson');
});

it('finds memories by key type filters and fts search', function () {
    $service = app(MemoryService::class);

    $service->store(MemoryType::Fact, 'user.name', 'Alice Johnson');
    $service->store(MemoryType::Preference, 'editor', 'I prefer neovim and dark mode');
    $service->store(MemoryType::Note, 'project.state', 'Release planned next month');

    $search = $service->search('dark mode');

    expect($service->findByKey('editor')?->type)->toBe(MemoryType::Preference)
        ->and($service->preferences())->toHaveCount(1)
        ->and($service->facts())->toHaveCount(1)
        ->and($search)->toHaveCount(1)
        ->and($search->first()->key)->toBe('editor');
});

it('extracts and stores facts from assistant responses', function () {
    $memoryService = app(MemoryService::class);
    $extractor = app(FactExtractor::class);
    $conversation = Conversation::factory()->create();

    $result = $extractor->extract('My name is Alice. I prefer dark mode. I use neovim daily.', $conversation);

    expect($result)->toHaveCount(3)
        ->and($memoryService->findByKey('user.name')?->value)->toBe('Alice')
        ->and($memoryService->findByKey('user.preference.dark_mode'))->not->toBeNull()
        ->and($memoryService->findByKey('user.tool.neovim'))->not->toBeNull();
});

it('supports memory and conversation artisan commands', function () {
    $conversationService = app(ConversationService::class);
    $messageService = app(MessageService::class);
    $memoryService = app(MemoryService::class);

    $conversation = $conversationService->create('CLI Chat');
    $messageService->store($conversation->id, MessageRole::User, 'hi there');
    $memoryService->store(MemoryType::Preference, 'theme', 'I prefer solarized dark', $conversation->id);

    test()->artisan('aegis:memory:search dark')
        ->expectsOutputToContain('theme')
        ->assertExitCode(0);

    test()->artisan('aegis:memory:list')
        ->expectsOutputToContain('preference')
        ->expectsOutputToContain('theme')
        ->assertExitCode(0);

    test()->artisan('aegis:conversations:list')
        ->expectsOutputToContain('CLI Chat')
        ->expectsOutputToContain('Messages')
        ->assertExitCode(0);
});
