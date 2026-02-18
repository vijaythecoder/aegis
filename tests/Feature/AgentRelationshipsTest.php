<?php

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Skill;
use App\Models\TokenUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('agent can have skills through agent_skills pivot', function () {
    $agent = Agent::factory()->create();
    $skill = Skill::factory()->create();

    $agent->skills()->attach($skill->id);

    expect($agent->skills)->toHaveCount(1);
    expect($agent->skills->first()->id)->toBe($skill->id);
});

it('agent can have tools through agent_tools table', function () {
    $agent = Agent::factory()->create();
    $toolClass = 'App\Tools\FileTool';

    $agent->tools()->create(['tool_class' => $toolClass]);

    expect($agent->tools)->toHaveCount(1);
    expect($agent->tools->first()->tool_class)->toBe($toolClass);
});

it('conversation belongs to agent', function () {
    $agent = Agent::factory()->create();
    $conversation = Conversation::factory()->create(['agent_id' => $agent->id]);

    expect($conversation->agent)->not->toBeNull();
    expect($conversation->agent->id)->toBe($agent->id);
});

it('conversation without agent_id is null', function () {
    $conversation = Conversation::factory()->create(['agent_id' => null]);

    expect($conversation->agent)->toBeNull();
});

it('agent has many conversations', function () {
    $agent = Agent::factory()->create();
    Conversation::factory(3)->create(['agent_id' => $agent->id]);

    expect($agent->conversations)->toHaveCount(3);
});

it('token usage can have agent_id', function () {
    $agent = Agent::factory()->create();
    $conversation = Conversation::factory()->create();
    $tokenUsage = TokenUsage::factory()->create([
        'conversation_id' => $conversation->id,
        'agent_id' => $agent->id,
    ]);

    expect($tokenUsage->agent_id)->toBe($agent->id);
});
