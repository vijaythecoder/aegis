<?php

use App\Models\Agent;
use App\Models\ChannelAgent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates channel agent mapping', function () {
    $agent = Agent::factory()->create(['name' => 'TelegramBot']);

    $channelAgent = ChannelAgent::query()->create([
        'channel' => 'telegram',
        'agent_id' => $agent->id,
    ]);

    expect($channelAgent->channel)->toBe('telegram')
        ->and($channelAgent->agent_id)->toBe($agent->id);
});

it('resolves channel agent via forChannel', function () {
    $agent = Agent::factory()->create(['name' => 'ChatBot']);

    ChannelAgent::query()->create([
        'channel' => 'imessage',
        'agent_id' => $agent->id,
    ]);

    $found = ChannelAgent::forChannel('imessage');

    expect($found)->not->toBeNull()
        ->and($found->agent_id)->toBe($agent->id);
});

it('returns null for unmapped channel', function () {
    $found = ChannelAgent::forChannel('slack');

    expect($found)->toBeNull();
});

it('enforces unique channel constraint', function () {
    ChannelAgent::query()->create(['channel' => 'telegram', 'agent_id' => null]);

    expect(fn () => ChannelAgent::query()->create(['channel' => 'telegram', 'agent_id' => null]))
        ->toThrow(\Illuminate\Database\QueryException::class);
});

it('allows nullable agent_id for channel mapping', function () {
    $mapping = ChannelAgent::query()->create([
        'channel' => 'discord',
        'agent_id' => null,
    ]);

    expect($mapping->agent_id)->toBeNull();
});

it('has belongs-to agent relationship', function () {
    $agent = Agent::factory()->create();

    $mapping = ChannelAgent::query()->create([
        'channel' => 'telegram',
        'agent_id' => $agent->id,
    ]);

    expect($mapping->agent->id)->toBe($agent->id)
        ->and($mapping->agent->name)->toBe($agent->name);
});

it('can update channel agent mapping', function () {
    $agentA = Agent::factory()->create(['name' => 'Agent A']);
    $agentB = Agent::factory()->create(['name' => 'Agent B']);

    ChannelAgent::query()->create([
        'channel' => 'telegram',
        'agent_id' => $agentA->id,
    ]);

    ChannelAgent::query()->updateOrCreate(
        ['channel' => 'telegram'],
        ['agent_id' => $agentB->id],
    );

    $mapping = ChannelAgent::forChannel('telegram');
    expect($mapping->agent_id)->toBe($agentB->id);
    expect(ChannelAgent::query()->where('channel', 'telegram')->count())->toBe(1);
});
