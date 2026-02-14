<?php

use App\Agent\AgentOrchestrator;
use App\Agent\StreamBuffer;
use App\Enums\MessageRole;
use App\Livewire\Chat;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Livewire\Livewire;
use Prism\Prism\Facades\Prism;
use Prism\Prism\Testing\TextResponseFake;

uses(RefreshDatabase::class);

it('streams tokens incrementally into stream buffer', function () {
    $conversation = Conversation::factory()->create();

    Prism::fake([
        TextResponseFake::make()->withText('streaming response text'),
    ])->withFakeChunkSize(4);

    $buffer = new StreamBuffer((string) $conversation->id);
    $orchestrator = app(AgentOrchestrator::class);

    $result = $orchestrator->respondStreaming('Say hello', $conversation->id, $buffer);

    $state = Cache::get('stream:'.$conversation->id);

    expect($result)->toBe('streaming response text')
        ->and($buffer->read())->toBe('streaming response text')
        ->and($state['writes'] ?? 0)->toBeGreaterThan(1)
        ->and($buffer->isActive())->toBeFalse()
        ->and($buffer->isCancelled())->toBeFalse();
});

it('stops active stream when cancelled and stores incomplete assistant message', function () {
    $conversation = Conversation::factory()->create();

    Prism::fake([
        TextResponseFake::make()->withText('abcdefghi'),
    ])->withFakeChunkSize(1);

    $buffer = new StreamBuffer((string) $conversation->id);
    $orchestrator = app(AgentOrchestrator::class);

    $result = $orchestrator->respondStreaming('Cancel this', $conversation->id, $buffer, function (string $delta, string $partial, StreamBuffer $streamBuffer): void {
        if (mb_strlen($partial) >= 3) {
            $streamBuffer->cancel();
        }
    });

    $assistant = Message::query()
        ->where('conversation_id', $conversation->id)
        ->where('role', MessageRole::Assistant)
        ->latest('id')
        ->first();

    expect($result)->toBe('abc')
        ->and($assistant)->not()->toBeNull()
        ->and($assistant?->content)->toBe('abc')
        ->and($assistant?->tool_result['is_complete'] ?? null)->toBeFalse()
        ->and($assistant?->tool_result['cancelled'] ?? null)->toBeTrue();
});

it('persists partial response content on cancellation', function () {
    $conversation = Conversation::factory()->create();

    Prism::fake([
        TextResponseFake::make()->withText('partial content target'),
    ])->withFakeChunkSize(2);

    $buffer = new StreamBuffer((string) $conversation->id);
    $orchestrator = app(AgentOrchestrator::class);

    $partial = $orchestrator->respondStreaming('Need partial', $conversation->id, $buffer, function (string $delta, string $content, StreamBuffer $streamBuffer): void {
        if (mb_strlen($content) >= 8) {
            $streamBuffer->cancel();
        }
    });

    $assistant = Message::query()
        ->where('conversation_id', $conversation->id)
        ->where('role', MessageRole::Assistant)
        ->latest('id')
        ->firstOrFail();

    expect($partial)->toBe($assistant->content)
        ->and($partial)->toBe('partial ')
        ->and($assistant->tool_result['is_complete'] ?? null)->toBeFalse();
});

it('chat component generates response via orchestrator', function () {
    $conversation = Conversation::factory()->create();

    $orchestrator = \Mockery::mock(AgentOrchestrator::class);
    $orchestrator->shouldReceive('respondStreaming')->once()->andReturn('done');
    app()->instance(AgentOrchestrator::class, $orchestrator);

    Livewire::test(Chat::class, ['conversationId' => $conversation->id])
        ->set('pendingMessage', 'test question')
        ->call('generateResponse')
        ->assertDispatched('agent-status-changed')
        ->assertDispatched('message-sent')
        ->assertSet('isThinking', false)
        ->assertSet('pendingMessage', '');
});
