<?php

use App\Agent\AegisAgent;
use App\Livewire\Chat;
use App\Models\Conversation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('chat component generates response via agent', function () {
    $conversation = Conversation::factory()->create();

    AegisAgent::fake(['Agent response']);

    Livewire::test(Chat::class, ['conversationId' => $conversation->id])
        ->set('pendingMessage', 'test question')
        ->call('generateResponse')
        ->assertDispatched('agent-status-changed')
        ->assertDispatched('message-sent')
        ->assertSet('isThinking', false)
        ->assertSet('pendingMessage', '');
});
