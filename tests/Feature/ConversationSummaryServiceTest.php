<?php

use App\Enums\MessageRole;
use App\Memory\ConversationSummaryService;
use App\Memory\MessageService;
use App\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Embeddings;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;

uses(RefreshDatabase::class);

it('generates summary for a conversation with messages', function () {
    Embeddings::fake();
    Prism::fake([
        TextResponseFake::make()->withText('Discussed memory system design for the Aegis AI assistant.'),
    ]);

    $conversation = Conversation::factory()->create();
    $messageService = app(MessageService::class);
    $messageService->store($conversation->id, MessageRole::User, 'How should we design the memory system?');
    $messageService->store($conversation->id, MessageRole::Assistant, 'I suggest a 5-layer architecture with middleware auto-injection.');

    $service = app(ConversationSummaryService::class);
    $summary = $service->summarize($conversation->id);

    expect($summary)->not->toBeNull()
        ->and($summary)->toContain('memory system')
        ->and($conversation->fresh()->summary)->toBe($summary);
});

it('returns null for conversation with no messages', function () {
    $conversation = Conversation::factory()->create();
    $service = app(ConversationSummaryService::class);

    $summary = $service->summarize($conversation->id);

    expect($summary)->toBeNull();
});

it('returns null for non-existent conversation', function () {
    $service = app(ConversationSummaryService::class);

    $summary = $service->summarize(999);

    expect($summary)->toBeNull();
});

it('summarizes all conversations without summaries', function () {
    Embeddings::fake();
    Prism::fake([
        TextResponseFake::make()->withText('First conversation summary.'),
        TextResponseFake::make()->withText('Second conversation summary.'),
    ]);

    $messageService = app(MessageService::class);

    $conv1 = Conversation::factory()->create(['summary' => null]);
    $messageService->store($conv1->id, MessageRole::User, 'Hello');
    $messageService->store($conv1->id, MessageRole::Assistant, 'Hi');

    $conv2 = Conversation::factory()->create(['summary' => null]);
    $messageService->store($conv2->id, MessageRole::User, 'Test');
    $messageService->store($conv2->id, MessageRole::Assistant, 'Response');

    $service = app(ConversationSummaryService::class);
    $count = $service->summarizeAll();

    expect($count)->toBe(2);
});
