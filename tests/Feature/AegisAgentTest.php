<?php

use App\Agent\AegisAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Contracts\Agent as AgentContract;
use Laravel\Ai\Contracts\Conversational;
use Laravel\Ai\Contracts\HasMiddleware;
use Laravel\Ai\Contracts\HasTools;
use Laravel\Ai\Contracts\Tool;

uses(RefreshDatabase::class);

it('implements Agent, Conversational, HasTools, and HasMiddleware interfaces', function () {
    $agent = app(AegisAgent::class);

    expect($agent)
        ->toBeInstanceOf(AgentContract::class)
        ->toBeInstanceOf(Conversational::class)
        ->toBeInstanceOf(HasTools::class)
        ->toBeInstanceOf(HasMiddleware::class);
});

it('returns instructions from SystemPromptBuilder', function () {
    $agent = app(AegisAgent::class);
    $instructions = $agent->instructions();

    expect((string) $instructions)->toContain('Aegis');
});

it('returns provider and model from config', function () {
    config(['aegis.agent.default_provider' => 'openai']);
    config(['aegis.agent.default_model' => 'gpt-4o']);

    $agent = app(AegisAgent::class);

    expect($agent->provider())->toBe('openai')
        ->and($agent->model())->toBe('gpt-4o');
});

it('returns timeout from config', function () {
    config(['aegis.agent.timeout' => 60]);

    $agent = app(AegisAgent::class);

    expect($agent->timeout())->toBe(60);
});

it('returns SDK tools from ToolRegistry', function () {
    $agent = app(AegisAgent::class);
    $tools = $agent->tools();

    expect($tools)->toBeArray();

    foreach ($tools as $tool) {
        expect($tool)->toBeInstanceOf(Tool::class);
    }
});

it('returns empty middleware array', function () {
    $agent = app(AegisAgent::class);

    expect($agent->middleware())->toBeArray()->toBeEmpty();
});

it('supports conversation memory via RemembersConversations', function () {
    $agent = app(AegisAgent::class);

    expect($agent->hasConversationParticipant())->toBeFalse()
        ->and($agent->currentConversation())->toBeNull()
        ->and($agent->messages())->toBeEmpty();

    $user = (object) ['id' => 1];
    $agent->forUser($user);

    expect($agent->hasConversationParticipant())->toBeTrue()
        ->and($agent->conversationParticipant())->toBe($user);
});

it('can continue an existing conversation', function () {
    $agent = app(AegisAgent::class);
    $user = (object) ['id' => 1];

    $agent->continue('conv-123', $user);

    expect($agent->currentConversation())->toBe('conv-123')
        ->and($agent->hasConversationParticipant())->toBeTrue();
});

it('can be faked and prompted', function () {
    AegisAgent::fake(['Hello from Aegis!']);

    $agent = app(AegisAgent::class);
    $response = $agent->prompt('Hello');

    expect($response->text)->toBe('Hello from Aegis!');

    AegisAgent::assertPrompted('Hello');
});

it('can be faked and streamed', function () {
    AegisAgent::fake(['Streamed response']);

    $agent = app(AegisAgent::class);
    $stream = $agent->stream('Hello');

    foreach ($stream as $event) {
        // consume the stream
    }

    expect($stream->text)->toBe('Streamed response');
});

it('respects max conversation messages config', function () {
    config(['aegis.memory.max_conversation_messages' => 50]);

    $agent = app(AegisAgent::class);

    expect($agent->messages())->toBeEmpty();
});
