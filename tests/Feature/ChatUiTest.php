<?php

use App\Agent\AegisAgent;
use App\Enums\MessageRole;
use App\Livewire\AgentStatus;
use App\Livewire\Chat;
use App\Livewire\ConversationSidebar;
use App\Memory\MessageService;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// ── Routes ────────────────────────────────────────────────────────────────────

it('has chat route', function () {
    $response = $this->get('/chat');

    $response->assertStatus(200);
});

it('redirects home to chat when onboarded', function () {
    \App\Models\Setting::create([
        'group' => 'app',
        'key' => 'onboarding_completed',
        'value' => true,
    ]);

    $response = $this->get('/');

    $response->assertRedirect('/chat');
});

it('has chat conversation route', function () {
    $conversation = Conversation::factory()->create();

    $response = $this->get("/chat/{$conversation->id}");

    $response->assertStatus(200);
});

// ── Chat Component ────────────────────────────────────────────────────────────

it('renders Chat component', function () {
    Livewire::test(Chat::class)
        ->assertStatus(200);
});

it('creates a new conversation on first message when none selected', function () {
    expect(Conversation::count())->toBe(0);

    Livewire::test(Chat::class)
        ->set('message', 'Hello world')
        ->call('sendMessage')
        ->assertSet('isThinking', true)
        ->assertSet('pendingMessage', 'Hello world');

    expect(Conversation::count())->toBe(1);
});

it('enters thinking state and stores pending message on send', function () {
    $conversation = Conversation::factory()->create();

    Livewire::test(Chat::class, ['conversationId' => $conversation->id])
        ->set('message', 'Test message')
        ->call('sendMessage')
        ->assertSet('isThinking', true)
        ->assertSet('message', '')
        ->assertSet('pendingMessage', 'Test message')
        ->assertDispatched('agent-status-changed');
});

it('generates response via agent', function () {
    $conversation = Conversation::factory()->create();

    AegisAgent::fake(['AI response here']);

    Livewire::test(Chat::class, ['conversationId' => $conversation->id])
        ->set('pendingMessage', 'Test question')
        ->call('generateResponse')
        ->assertSet('isThinking', false)
        ->assertSet('pendingMessage', '')
        ->assertDispatched('message-sent');
});

it('clears input after sending message', function () {
    $conversation = Conversation::factory()->create();

    Livewire::test(Chat::class, ['conversationId' => $conversation->id])
        ->set('message', 'My message')
        ->call('sendMessage')
        ->assertSet('message', '');
});

it('does not send empty message', function () {
    Livewire::test(Chat::class)
        ->set('message', '')
        ->call('sendMessage')
        ->assertSet('isThinking', false);

    expect(Conversation::count())->toBe(0);
});

it('loads existing messages for a conversation', function () {
    $conversation = Conversation::factory()->create();
    $service = app(MessageService::class);

    $service->store($conversation->id, MessageRole::User, 'Previous question');
    $service->store($conversation->id, MessageRole::Assistant, 'Previous answer');

    Livewire::test(Chat::class, ['conversationId' => $conversation->id])
        ->assertSee('Previous question')
        ->assertSee('Previous answer');
});

it('switches conversation on event', function () {
    $conversation = Conversation::factory()->create();
    $service = app(MessageService::class);
    $service->store($conversation->id, MessageRole::User, 'Hello from convo');

    Livewire::test(Chat::class)
        ->dispatch('conversation-selected', conversationId: $conversation->id)
        ->assertSee('Hello from convo');
});

it('renders markdown in assistant messages', function () {
    $conversation = Conversation::factory()->create();
    Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'assistant',
        'content' => '**bold text**',
    ]);

    Livewire::test(Chat::class, ['conversationId' => $conversation->id])
        ->assertSeeHtml('<strong>bold text</strong>');
});

it('displays tool messages with tool name', function () {
    $conversation = Conversation::factory()->create();
    Message::factory()->create([
        'conversation_id' => $conversation->id,
        'role' => 'tool',
        'content' => 'file contents here',
        'tool_name' => 'read_file',
    ]);

    Livewire::test(Chat::class, ['conversationId' => $conversation->id])
        ->assertSee('read_file')
        ->assertSee('file contents here');
});

it('shows pending message in chat while thinking', function () {
    $conversation = Conversation::factory()->create();

    Livewire::test(Chat::class, ['conversationId' => $conversation->id])
        ->set('message', 'Think about this')
        ->call('sendMessage')
        ->assertSet('isThinking', true)
        ->assertSee('Think about this');
});

// ── ConversationSidebar Component ─────────────────────────────────────────────

it('renders ConversationSidebar component', function () {
    Livewire::test(ConversationSidebar::class)
        ->assertStatus(200);
});

it('lists conversations', function () {
    Conversation::factory()->create(['title' => 'First Chat']);
    Conversation::factory()->create(['title' => 'Second Chat']);

    Livewire::test(ConversationSidebar::class)
        ->assertSee('First Chat')
        ->assertSee('Second Chat');
});

it('creates a new conversation from sidebar', function () {
    expect(Conversation::count())->toBe(0);

    Livewire::test(ConversationSidebar::class)
        ->call('createConversation')
        ->assertRedirect();

    expect(Conversation::count())->toBe(1);
});

it('deletes a conversation', function () {
    $conversation = Conversation::factory()->create();

    Livewire::test(ConversationSidebar::class)
        ->call('deleteConversation', $conversation->id);

    expect(Conversation::count())->toBe(0);
});

it('selects a conversation and dispatches event', function () {
    $conversation = Conversation::factory()->create();

    Livewire::test(ConversationSidebar::class)
        ->call('selectConversation', $conversation->id)
        ->assertSet('activeConversationId', $conversation->id)
        ->assertRedirect(route('chat.conversation', $conversation->id));
});

it('searches conversations by title', function () {
    Conversation::factory()->create(['title' => 'Laravel migrations']);
    Conversation::factory()->create(['title' => 'React components']);

    Livewire::test(ConversationSidebar::class)
        ->set('search', 'Laravel')
        ->assertSee('Laravel migrations')
        ->assertDontSee('React components');
});

it('highlights active conversation', function () {
    $conversation = Conversation::factory()->create();

    Livewire::test(ConversationSidebar::class, ['activeConversationId' => $conversation->id])
        ->assertSet('activeConversationId', $conversation->id);
});

// ── AgentStatus Component ─────────────────────────────────────────────────────

it('renders AgentStatus component', function () {
    Livewire::test(AgentStatus::class)
        ->assertStatus(200);
});

it('displays provider and model from config', function () {
    config(['aegis.agent.default_provider' => 'anthropic']);
    config(['aegis.agent.default_model' => 'claude-sonnet-4']);

    Livewire::test(AgentStatus::class)
        ->assertSee('anthropic')
        ->assertSee('claude-sonnet-4');
});

it('shows idle state by default', function () {
    Livewire::test(AgentStatus::class)
        ->assertSet('state', 'idle');
});

it('updates state on status-changed event', function () {
    Livewire::test(AgentStatus::class)
        ->dispatch('agent-status-changed', state: 'thinking')
        ->assertSet('state', 'thinking');
});

it('shows token count for conversation', function () {
    $conversation = Conversation::factory()->create();
    $service = app(MessageService::class);
    $service->store($conversation->id, MessageRole::User, 'Hello world test message');

    Livewire::test(AgentStatus::class, ['conversationId' => $conversation->id])
        ->assertSee('tokens');
});
