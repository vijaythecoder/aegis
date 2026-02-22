<?php

use App\Enums\MessageRole;
use App\Livewire\Chat;
use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

// ── extractMention ───────────────────────────────────────────────────────────

it('matches agent by name case-insensitively', function () {
    $agent = Agent::factory()->create(['name' => 'FitCoach', 'slug' => 'fitcoach', 'is_active' => true]);

    Livewire::test(Chat::class)
        ->set('message', '@FitCoach help me plan workouts')
        ->call('sendMessage')
        ->assertDispatched('conversation-selected');

    $conversation = Conversation::where('agent_id', $agent->id)->first();
    expect($conversation)->not->toBeNull();
});

it('matches agent name case-insensitively with lowercase input', function () {
    $agent = Agent::factory()->create(['name' => 'FitCoach', 'slug' => 'fitcoach', 'is_active' => true]);

    Livewire::test(Chat::class)
        ->set('message', '@fitcoach help me')
        ->call('sendMessage')
        ->assertDispatched('conversation-selected');

    expect(Conversation::where('agent_id', $agent->id)->exists())->toBeTrue();
});

it('matches agent by slug', function () {
    $agent = Agent::factory()->create(['name' => 'Tax Helper', 'slug' => 'tax-helper', 'is_active' => true]);

    Livewire::test(Chat::class)
        ->set('message', '@tax-helper file my taxes')
        ->call('sendMessage')
        ->assertDispatched('conversation-selected');

    expect(Conversation::where('agent_id', $agent->id)->exists())->toBeTrue();
});

it('extracts message text after mention', function () {
    $agent = Agent::factory()->create(['name' => 'Coach', 'slug' => 'coach', 'is_active' => true]);

    Livewire::test(Chat::class)
        ->set('message', '@Coach plan my week')
        ->call('sendMessage')
        ->assertSet('pendingMessage', 'plan my week')
        ->assertDispatched('conversation-selected');
});

it('defaults to Hello when no message after mention', function () {
    $agent = Agent::factory()->create(['name' => 'Coach', 'slug' => 'coach', 'is_active' => true]);

    Livewire::test(Chat::class)
        ->set('message', '@Coach')
        ->call('sendMessage')
        ->assertSet('pendingMessage', 'Hello!')
        ->assertDispatched('conversation-selected');
});

it('returns null for text without @ prefix', function () {
    Agent::factory()->create(['name' => 'Coach', 'slug' => 'coach', 'is_active' => true]);

    // Normal message — should NOT route to agent, should create regular conversation
    Livewire::test(Chat::class)
        ->set('message', 'Coach help me')
        ->call('sendMessage')
        ->assertNotDispatched('conversation-selected');

    // A regular conversation was created (not agent-linked)
    $conversation = Conversation::first();
    expect($conversation)->not->toBeNull()
        ->and($conversation->agent_id)->toBeNull();
});

it('returns null when mentioned agent does not exist', function () {
    // No agents created — @Unknown should not match
    Livewire::test(Chat::class)
        ->set('message', '@Unknown do something')
        ->call('sendMessage')
        ->assertNotDispatched('conversation-selected');

    // Should create a regular conversation with the full text
    $conversation = Conversation::first();
    expect($conversation)->not->toBeNull()
        ->and($conversation->agent_id)->toBeNull();
});

it('only matches active agents', function () {
    Agent::factory()->create(['name' => 'Ghost', 'slug' => 'ghost', 'is_active' => false]);

    Livewire::test(Chat::class)
        ->set('message', '@Ghost hello')
        ->call('sendMessage')
        ->assertNotDispatched('conversation-selected');

    // Falls through to normal flow — regular conversation
    expect(Conversation::whereNotNull('agent_id')->exists())->toBeFalse();
});

it('prefers name match over slug match', function () {
    // Create an agent whose slug happens to match another agent's name
    $agentA = Agent::factory()->create(['name' => 'alpha', 'slug' => 'alpha', 'is_active' => true]);
    Agent::factory()->create(['name' => 'Beta', 'slug' => 'alpha-bot', 'is_active' => true]);

    Livewire::test(Chat::class)
        ->set('message', '@alpha test')
        ->call('sendMessage')
        ->assertDispatched('conversation-selected');

    // Should route to agentA (name match)
    expect(Conversation::where('agent_id', $agentA->id)->exists())->toBeTrue();
});

// ── routeToAgent ─────────────────────────────────────────────────────────────

it('creates new agent conversation when none exists', function () {
    $agent = Agent::factory()->create(['name' => 'Helper', 'slug' => 'helper', 'is_active' => true]);

    expect(Conversation::where('agent_id', $agent->id)->count())->toBe(0);

    Livewire::test(Chat::class)
        ->set('message', '@Helper what can you do?')
        ->call('sendMessage');

    expect(Conversation::where('agent_id', $agent->id)->count())->toBe(1);
});

it('reuses existing agent conversation', function () {
    $agent = Agent::factory()->create(['name' => 'Helper', 'slug' => 'helper', 'is_active' => true]);
    $existing = Conversation::factory()->create(['agent_id' => $agent->id, 'last_message_at' => now()]);

    Livewire::test(Chat::class)
        ->set('message', '@Helper what can you do?')
        ->call('sendMessage')
        ->assertSet('conversationId', $existing->id);

    // Should NOT create a new conversation
    expect(Conversation::where('agent_id', $agent->id)->count())->toBe(1);
});

it('inserts system message in source conversation when routing', function () {
    $agent = Agent::factory()->create(['name' => 'Helper', 'slug' => 'helper', 'is_active' => true]);
    $sourceConversation = Conversation::factory()->create(['agent_id' => null]);

    Livewire::test(Chat::class, ['conversationId' => $sourceConversation->id])
        ->set('message', '@Helper check this out')
        ->call('sendMessage');

    // Source conversation should have a system message about the routing
    $systemMessage = Message::where('conversation_id', $sourceConversation->id)
        ->where('role', MessageRole::System)
        ->first();

    expect($systemMessage)->not->toBeNull()
        ->and($systemMessage->content)->toContain('routed to @Helper')
        ->and($systemMessage->content)->toContain('check this out');
});

it('does not insert system message when no source conversation', function () {
    $agent = Agent::factory()->create(['name' => 'Helper', 'slug' => 'helper', 'is_active' => true]);

    // No conversationId set — starting fresh
    Livewire::test(Chat::class)
        ->set('message', '@Helper hello')
        ->call('sendMessage');

    // No system messages should exist in the new agent conversation
    $agentConvo = Conversation::where('agent_id', $agent->id)->first();
    $systemMessages = Message::where('conversation_id', $agentConvo->id)
        ->where('role', MessageRole::System)
        ->count();

    expect($systemMessages)->toBe(0);
});

it('sets isThinking to true after routing', function () {
    Agent::factory()->create(['name' => 'Helper', 'slug' => 'helper', 'is_active' => true]);

    Livewire::test(Chat::class)
        ->set('message', '@Helper do something')
        ->call('sendMessage')
        ->assertSet('isThinking', true);
});

it('clears message input after routing', function () {
    Agent::factory()->create(['name' => 'Helper', 'slug' => 'helper', 'is_active' => true]);

    Livewire::test(Chat::class)
        ->set('message', '@Helper do something')
        ->call('sendMessage')
        ->assertSet('message', '');
});

it('dispatches agent-status-changed event when routing', function () {
    Agent::factory()->create(['name' => 'Helper', 'slug' => 'helper', 'is_active' => true]);

    Livewire::test(Chat::class)
        ->set('message', '@Helper do something')
        ->call('sendMessage')
        ->assertDispatched('agent-status-changed');
});

it('switches conversationId to agent conversation', function () {
    $agent = Agent::factory()->create(['name' => 'Helper', 'slug' => 'helper', 'is_active' => true]);
    $agentConvo = Conversation::factory()->create(['agent_id' => $agent->id, 'last_message_at' => now()]);

    // Start in a different conversation
    $originalConvo = Conversation::factory()->create(['agent_id' => null]);

    Livewire::test(Chat::class, ['conversationId' => $originalConvo->id])
        ->set('message', '@Helper switch to you')
        ->call('sendMessage')
        ->assertSet('conversationId', $agentConvo->id);
});

it('does not create regular conversation when mention is detected', function () {
    Agent::factory()->create(['name' => 'Helper', 'slug' => 'helper', 'is_active' => true]);

    // Start with no conversation
    Livewire::test(Chat::class)
        ->set('message', '@Helper hello')
        ->call('sendMessage');

    // Only the agent conversation should exist — no null-agent conversation
    $conversations = Conversation::all();
    expect($conversations)->toHaveCount(1)
        ->and($conversations->first()->agent_id)->not->toBeNull();
});

it('picks most recent agent conversation when multiple exist', function () {
    $agent = Agent::factory()->create(['name' => 'Helper', 'slug' => 'helper', 'is_active' => true]);

    $old = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'last_message_at' => now()->subDays(5),
    ]);
    $recent = Conversation::factory()->create([
        'agent_id' => $agent->id,
        'last_message_at' => now(),
    ]);

    Livewire::test(Chat::class)
        ->set('message', '@Helper which convo?')
        ->call('sendMessage')
        ->assertSet('conversationId', $recent->id);
});
