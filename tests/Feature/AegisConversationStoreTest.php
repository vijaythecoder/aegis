<?php

use App\Agent\AegisConversationStore;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Messages\Message as SdkMessage;
use Laravel\Ai\Prompts\AgentPrompt;
use Laravel\Ai\Responses\AgentResponse;
use Laravel\Ai\Responses\Data\Meta;
use Laravel\Ai\Responses\Data\Usage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->store = new AegisConversationStore;
});

function createTestPrompt(string $text): AgentPrompt
{
    $agent = Mockery::mock(\Laravel\Ai\Contracts\Agent::class);
    $provider = Mockery::mock(\Laravel\Ai\Contracts\Providers\TextProvider::class);

    return new AgentPrompt(
        agent: $agent,
        prompt: $text,
        attachments: [],
        provider: $provider,
        model: 'test-model',
    );
}

function createTestResponse(string $text, int $promptTokens = 0, int $completionTokens = 0): AgentResponse
{
    return new AgentResponse(
        invocationId: 'test-invocation',
        text: $text,
        usage: new Usage(promptTokens: $promptTokens, completionTokens: $completionTokens),
        meta: new Meta(provider: 'test', model: 'test-model'),
    );
}

it('implements the SDK ConversationStore contract', function () {
    expect($this->store)->toBeInstanceOf(ConversationStore::class);
});

it('stores a new conversation and returns its string ID', function () {
    $id = $this->store->storeConversation(userId: null, title: 'Test Conversation');

    expect($id)->toBeString()->not->toBeEmpty();

    $conversation = Conversation::find((int) $id);
    expect($conversation)->not->toBeNull();
    expect($conversation->title)->toBe('Test Conversation');
    expect($conversation->last_message_at)->not->toBeNull();
});

it('stores a user message in the messages table', function () {
    $conversation = Conversation::factory()->create();
    $conversationId = (string) $conversation->id;

    $prompt = createTestPrompt('Hello, how are you?');

    $messageId = $this->store->storeUserMessage($conversationId, null, $prompt);

    expect($messageId)->toBeString()->not->toBeEmpty();

    $message = Message::find((int) $messageId);
    expect($message)->not->toBeNull();
    expect($message->conversation_id)->toBe($conversation->id);
    expect($message->role->value)->toBe('user');
    expect($message->content)->toBe('Hello, how are you?');
});

it('stores an assistant message with token usage', function () {
    $conversation = Conversation::factory()->create();
    $conversationId = (string) $conversation->id;

    $prompt = createTestPrompt('Tell me a joke');
    $response = createTestResponse('Why did the chicken cross the road?', promptTokens: 10, completionTokens: 25);

    $messageId = $this->store->storeAssistantMessage($conversationId, null, $prompt, $response);

    expect($messageId)->toBeString()->not->toBeEmpty();

    $message = Message::find((int) $messageId);
    expect($message)->not->toBeNull();
    expect($message->conversation_id)->toBe($conversation->id);
    expect($message->role->value)->toBe('assistant');
    expect($message->content)->toBe('Why did the chicken cross the road?');
    expect($message->tokens_used)->toBe(35);
});

it('updates last_message_at when storing assistant message', function () {
    $conversation = Conversation::factory()->create(['last_message_at' => now()->subHour()]);
    $conversationId = (string) $conversation->id;

    $prompt = createTestPrompt('Hi');
    $response = createTestResponse('Hello!');

    $this->store->storeAssistantMessage($conversationId, null, $prompt, $response);

    $conversation->refresh();
    expect($conversation->last_message_at->diffInMinutes(now()))->toBeLessThan(1);
});

it('retrieves latest conversation messages as SDK Message objects', function () {
    $conversation = Conversation::factory()->create();

    Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'First message',
        'created_at' => now()->subMinutes(3),
    ]);
    Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'content' => 'First response',
        'created_at' => now()->subMinutes(2),
    ]);
    Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'Second message',
        'created_at' => now()->subMinute(),
    ]);

    $messages = $this->store->getLatestConversationMessages((string) $conversation->id, limit: 10);

    expect($messages)->toHaveCount(3);
    expect($messages->first())->toBeInstanceOf(SdkMessage::class);
    expect($messages->first()->content)->toBe('First message');
    expect($messages->first()->role->value)->toBe('user');
    expect($messages->last()->content)->toBe('Second message');
});

it('respects the limit parameter for conversation messages', function () {
    $conversation = Conversation::factory()->create();

    Message::factory()->count(5)->create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
    ]);

    $messages = $this->store->getLatestConversationMessages((string) $conversation->id, limit: 2);

    expect($messages)->toHaveCount(2);
});

it('returns the most recent messages when limited', function () {
    $conversation = Conversation::factory()->create();

    for ($i = 1; $i <= 5; $i++) {
        Message::factory()->create([
            'conversation_id' => $conversation->id,
            'role' => 'user',
            'content' => "Message {$i}",
            'created_at' => now()->subMinutes(6 - $i),
        ]);
    }

    $messages = $this->store->getLatestConversationMessages((string) $conversation->id, limit: 2);

    expect($messages)->toHaveCount(2);
    expect($messages->first()->content)->toBe('Message 4');
    expect($messages->last()->content)->toBe('Message 5');
});

it('returns null for latestConversationId when no conversations exist', function () {
    $id = $this->store->latestConversationId(userId: 1);

    expect($id)->toBeNull();
});

it('returns the latest conversation ID', function () {
    Conversation::factory()->create(['last_message_at' => now()->subHour()]);
    $new = Conversation::factory()->create(['last_message_at' => now()]);

    $id = $this->store->latestConversationId(userId: 1);

    expect($id)->toBe((string) $new->id);
});

it('is bound in the container as the ConversationStore', function () {
    $resolved = app(ConversationStore::class);

    expect($resolved)->toBeInstanceOf(AegisConversationStore::class);
});
