<?php

use App\Livewire\AgentSettings;
use App\Models\Agent;
use App\Models\Skill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders agent settings component', function () {
    Livewire::test(AgentSettings::class)->assertStatus(200);
});

it('lists user agents excluding default aegis', function () {
    Agent::factory()->create(['name' => 'DefaultBot', 'slug' => 'aegis']);
    Agent::factory()->create(['name' => 'FitCoach', 'slug' => 'fitcoach']);

    Livewire::test(AgentSettings::class)
        ->assertSee('FitCoach')
        ->assertDontSee('DefaultBot');
});

it('creates a new agent', function () {
    Livewire::test(AgentSettings::class)
        ->set('showForm', true)
        ->set('name', 'TestBot')
        ->set('avatar', '🤖')
        ->set('persona', 'You are a test bot.')
        ->call('createAgent');

    expect(Agent::where('slug', 'testbot')->exists())->toBeTrue();
});

it('validates required fields on create', function () {
    Livewire::test(AgentSettings::class)
        ->set('showForm', true)
        ->set('name', '')
        ->set('persona', '')
        ->call('createAgent')
        ->assertHasErrors(['name', 'persona']);
});

it('updates an existing agent', function () {
    $agent = Agent::factory()->create(['name' => 'OldName']);

    Livewire::test(AgentSettings::class)
        ->call('editAgent', $agent->id)
        ->set('name', 'NewName')
        ->call('updateAgent');

    expect($agent->fresh()->name)->toBe('NewName');
});

it('deletes an agent', function () {
    $agent = Agent::factory()->create(['slug' => 'deleteme']);

    Livewire::test(AgentSettings::class)
        ->call('deleteAgent', $agent->id);

    expect(Agent::where('slug', 'deleteme')->exists())->toBeFalse();
});

it('prevents deleting default aegis agent', function () {
    $agent = Agent::factory()->create(['slug' => 'aegis']);

    Livewire::test(AgentSettings::class)
        ->call('deleteAgent', $agent->id);

    expect(Agent::where('slug', 'aegis')->exists())->toBeTrue();
});

it('toggles agent active status', function () {
    $agent = Agent::factory()->create(['is_active' => true]);

    Livewire::test(AgentSettings::class)
        ->call('toggleActive', $agent->id);

    expect($agent->fresh()->is_active)->toBeFalse();
});

it('enforces max 10 agents limit', function () {
    Agent::factory()->count(10)->create();

    Livewire::test(AgentSettings::class)
        ->set('showForm', true)
        ->set('name', 'Eleventh Agent')
        ->set('persona', 'Too many agents')
        ->call('createAgent');

    expect(Agent::count())->toBe(10);
});

it('assigns skills to agent on create', function () {
    $skill = Skill::factory()->create();

    Livewire::test(AgentSettings::class)
        ->set('showForm', true)
        ->set('name', 'SkillBot')
        ->set('persona', 'Has skills')
        ->set('selectedSkills', [$skill->id])
        ->call('createAgent');

    $agent = Agent::where('slug', 'skillbot')->first();
    expect($agent->skills()->count())->toBe(1);
});
