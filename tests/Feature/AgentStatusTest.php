<?php

use App\Agent\AegisAgent;
use App\Agent\PlanningAgent;
use App\Agent\ReflectionAgent;
use App\Livewire\Chat;
use App\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('dispatches thinking status when sending a message', function () {
    AegisAgent::fake(['Simple response']);

    Livewire::test(Chat::class)
        ->set('message', 'Hello')
        ->call('sendMessage')
        ->assertDispatched('agent-status-changed', state: 'thinking');
});

it('dispatches idle status after response completes', function () {
    AegisAgent::fake(['Simple response']);

    $conversation = Conversation::factory()->create();

    Livewire::test(Chat::class, ['conversationId' => $conversation->id])
        ->set('message', 'Hello')
        ->call('sendMessage')
        ->call('generateResponse')
        ->assertDispatched('agent-status-changed', state: 'idle');
});

it('dispatches planning status for complex queries', function () {
    PlanningAgent::fake(['STEP 1: Research']);
    AegisAgent::fake(['Detailed response']);
    ReflectionAgent::fake(['APPROVED: Good']);

    config()->set('aegis.agent.planning_enabled', true);
    config()->set('aegis.agent.reflection_enabled', true);

    $conversation = Conversation::factory()->create();

    Livewire::test(Chat::class, ['conversationId' => $conversation->id])
        ->set('message', 'Create a comprehensive analysis of PHP 8.4 features with detailed code examples and comparisons')
        ->call('sendMessage')
        ->call('generateResponse')
        ->assertDispatched('agent-status-changed', state: 'planning');
});

it('renders the chat view with status indicator markup', function () {
    Livewire::test(Chat::class)
        ->assertSee('agentPhase')
        ->assertSee('phaseLabel');
});
