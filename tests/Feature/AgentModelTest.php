<?php

use App\Models\Agent;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates an agent with factory', function () {
    $agent = Agent::factory()->create();

    expect($agent)->toBeInstanceOf(Agent::class);
    expect($agent->id)->toBeGreaterThan(0);
    expect($agent->name)->not->toBeEmpty();
    expect($agent->slug)->not->toBeEmpty();
    expect($agent->persona)->not->toBeEmpty();
    expect($agent->is_active)->toBeTrue();
});

it('casts settings as array', function () {
    $agent = Agent::factory()->create([
        'settings' => ['theme' => 'dark', 'notifications' => true],
    ]);

    expect($agent->settings)->toBeArray();
    expect($agent->settings['theme'])->toBe('dark');
    expect($agent->settings['notifications'])->toBeTrue();
});

it('casts is_active as boolean', function () {
    $agent = Agent::factory()->create(['is_active' => true]);

    expect($agent->is_active)->toBeTrue();
});

it('generates unique slug', function () {
    $agent1 = Agent::factory()->create(['name' => 'Test Agent']);
    $agent2 = Agent::factory()->create(['name' => 'Another Agent']);

    expect($agent1->slug)->not->toBe($agent2->slug);
});

it('has inactive state', function () {
    $agent = Agent::factory()->inactive()->create();

    expect($agent->is_active)->toBeFalse();
});

it('has withPersona state', function () {
    $customPersona = 'I am a helpful assistant focused on productivity.';
    $agent = Agent::factory()->withPersona($customPersona)->create();

    expect($agent->persona)->toBe($customPersona);
});

it('has conversations relationship', function () {
    $agent = Agent::factory()->create();

    expect($agent->conversations())->not->toBeNull();
});

it('has skills relationship', function () {
    $agent = Agent::factory()->create();

    expect($agent->skills())->not->toBeNull();
});
