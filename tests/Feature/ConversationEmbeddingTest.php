<?php

use App\Enums\MessageRole;
use App\Listeners\EmbedConversationMessage;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Embeddings;

uses(RefreshDatabase::class);

it('creates embedding when user message is saved', function () {
    Embeddings::fake();

    $conversation = Conversation::factory()->create();
    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::User,
        'content' => 'Tell me about Laravel',
    ]);

    $listener = app(EmbedConversationMessage::class);
    $listener->handleMessageCreated($message);

    $embeddings = DB::table('vector_embeddings')
        ->where('source_type', 'message')
        ->where('source_id', $message->id)
        ->count();

    expect($embeddings)->toBe(1);

    Embeddings::assertGenerated(fn ($prompt) => $prompt->contains('Tell me about Laravel'));
});

it('creates embedding when assistant message is saved', function () {
    Embeddings::fake();

    $conversation = Conversation::factory()->create();
    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::Assistant,
        'content' => 'Laravel is a PHP framework',
    ]);

    $listener = app(EmbedConversationMessage::class);
    $listener->handleMessageCreated($message);

    $embeddings = DB::table('vector_embeddings')
        ->where('source_type', 'message')
        ->where('source_id', $message->id)
        ->count();

    expect($embeddings)->toBe(1);
});

it('skips system messages', function () {
    Embeddings::fake();

    $conversation = Conversation::factory()->create();
    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::System,
        'content' => 'You are a helpful assistant',
    ]);

    $listener = app(EmbedConversationMessage::class);
    $listener->handleMessageCreated($message);

    $embeddings = DB::table('vector_embeddings')->count();

    expect($embeddings)->toBe(0);

    Embeddings::assertNothingGenerated();
});

it('skips tool messages', function () {
    Embeddings::fake();

    $conversation = Conversation::factory()->create();
    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::Tool,
        'content' => '{"result": "file contents"}',
    ]);

    $listener = app(EmbedConversationMessage::class);
    $listener->handleMessageCreated($message);

    $embeddings = DB::table('vector_embeddings')->count();

    expect($embeddings)->toBe(0);
});

it('skips embedding when provider is disabled', function () {
    config(['aegis.memory.embedding_provider' => 'disabled']);

    $conversation = Conversation::factory()->create();
    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::User,
        'content' => 'Hello world',
    ]);

    $listener = app(EmbedConversationMessage::class);
    $listener->handleMessageCreated($message);

    $embeddings = DB::table('vector_embeddings')->count();

    expect($embeddings)->toBe(0);

    Embeddings::assertNothingGenerated();
});

it('does not block message saving when embedding fails', function () {
    Embeddings::fake(function () {
        throw new RuntimeException('Provider unavailable');
    });

    $conversation = Conversation::factory()->create();
    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::User,
        'content' => 'This should still save',
    ]);

    $listener = app(EmbedConversationMessage::class);
    $listener->handleMessageCreated($message);

    expect($message->exists)->toBeTrue();

    $embeddings = DB::table('vector_embeddings')->count();
    expect($embeddings)->toBe(0);
});

it('stores conversation_id in embedding metadata', function () {
    Embeddings::fake();

    $conversation = Conversation::factory()->create();
    $message = Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => MessageRole::User,
        'content' => 'Test with conversation context',
    ]);

    $listener = app(EmbedConversationMessage::class);
    $listener->handleMessageCreated($message);

    $embedding = DB::table('vector_embeddings')
        ->where('source_type', 'message')
        ->where('source_id', $message->id)
        ->first();

    expect($embedding->conversation_id)->toBe($conversation->id);
});
