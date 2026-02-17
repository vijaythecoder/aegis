<?php

use App\Livewire\TokenDashboard;
use App\Models\Conversation;
use App\Models\TokenUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('usage page is accessible', function () {
    $this->get('/usage')->assertStatus(200);
});

test('usage page renders livewire component', function () {
    $this->get('/usage')->assertSeeLivewire('token-dashboard');
});

test('shows empty state when no usage data', function () {
    Livewire::test(TokenDashboard::class)
        ->assertSee('No usage data recorded yet');
});

test('shows summary cards with data', function () {
    $conversation = Conversation::factory()->create();

    TokenUsage::factory()->count(3)->create([
        'conversation_id' => $conversation->id,
        'provider' => 'anthropic',
        'model' => 'claude-sonnet-4-20250514',
        'prompt_tokens' => 1000,
        'completion_tokens' => 500,
        'total_tokens' => 1500,
        'estimated_cost' => 0.0105,
    ]);

    Livewire::test(TokenDashboard::class)
        ->assertSee('Total Cost')
        ->assertSee('Total Tokens')
        ->assertSee('Total Requests')
        ->assertDontSee('No usage data recorded yet');
});

test('can switch period', function () {
    $conversation = Conversation::factory()->create();

    TokenUsage::factory()->create([
        'conversation_id' => $conversation->id,
        'estimated_cost' => 0.05,
    ]);

    Livewire::test(TokenDashboard::class)
        ->call('setPeriod', '30d')
        ->assertSet('period', '30d')
        ->assertDispatched('refresh-charts');
});

test('shows model breakdown table', function () {
    $conversation = Conversation::factory()->create();

    TokenUsage::factory()->create([
        'conversation_id' => $conversation->id,
        'provider' => 'anthropic',
        'model' => 'claude-sonnet-4-20250514',
    ]);

    Livewire::test(TokenDashboard::class)
        ->assertSee('claude-sonnet-4-20250514')
        ->assertSee('anthropic');
});

test('shows provider breakdown', function () {
    $conversation = Conversation::factory()->create();

    TokenUsage::factory()->create([
        'conversation_id' => $conversation->id,
        'provider' => 'openai',
        'model' => 'gpt-4o',
    ]);

    TokenUsage::factory()->create([
        'conversation_id' => $conversation->id,
        'provider' => 'anthropic',
        'model' => 'claude-sonnet-4-20250514',
    ]);

    Livewire::test(TokenDashboard::class)
        ->assertSee('openai')
        ->assertSee('anthropic');
});

test('sidebar has token usage link', function () {
    $this->get('/chat')
        ->assertSee('Token Usage');
});
