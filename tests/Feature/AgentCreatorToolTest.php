<?php

use App\Models\Agent;
use App\Models\Conversation;
use App\Models\Skill;
use App\Tools\AgentCreatorTool;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Ai\Tools\Request;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->tool = new AgentCreatorTool;
});

it('creates an agent with persona and avatar', function () {
    $result = $this->tool->handle(new Request([
        'action' => 'create',
        'name' => 'FitCoach',
        'persona' => 'Strict fitness coach focused on strength training',
        'avatar' => '💪',
    ]));

    expect((string) $result)->toContain('Created agent "FitCoach"')
        ->and((string) $result)->toContain('💪');

    $agent = Agent::query()->where('slug', 'fitcoach')->first();
    expect($agent)->not->toBeNull()
        ->and($agent->name)->toBe('FitCoach')
        ->and($agent->persona)->toContain('strength training')
        ->and($agent->avatar)->toBe('💪')
        ->and($agent->is_active)->toBeTrue();
});

it('creates a conversation for the new agent', function () {
    $this->tool->handle(new Request([
        'action' => 'create',
        'name' => 'TaxHelper',
        'persona' => 'Tax preparation specialist',
    ]));

    $agent = Agent::query()->where('slug', 'taxhelper')->first();
    $conversation = Conversation::query()->where('agent_id', $agent->id)->first();

    expect($conversation)->not->toBeNull()
        ->and($conversation->title)->toContain('TaxHelper');
});

it('attaches skills by slug on create', function () {
    Skill::factory()->create(['slug' => 'health-fitness', 'name' => 'Health & Fitness', 'is_active' => true]);
    Skill::factory()->create(['slug' => 'schedule-manager', 'name' => 'Schedule Manager', 'is_active' => true]);

    $result = $this->tool->handle(new Request([
        'action' => 'create',
        'name' => 'Wellness Coach',
        'persona' => 'Holistic wellness advisor',
        'suggested_skills' => ['health-fitness', 'schedule-manager'],
    ]));

    $agent = Agent::query()->where('slug', 'wellness-coach')->first();
    expect($agent->skills)->toHaveCount(2)
        ->and((string) $result)->toContain('Health & Fitness');
});

it('ignores invalid skill slugs', function () {
    Skill::factory()->create(['slug' => 'health-fitness', 'name' => 'Health & Fitness', 'is_active' => true]);

    $this->tool->handle(new Request([
        'action' => 'create',
        'name' => 'TestAgent',
        'persona' => 'Test persona',
        'suggested_skills' => ['health-fitness', 'nonexistent-skill'],
    ]));

    $agent = Agent::query()->where('slug', 'testagent')->first();
    expect($agent->skills)->toHaveCount(1);
});

it('defaults avatar to robot emoji', function () {
    $this->tool->handle(new Request([
        'action' => 'create',
        'name' => 'NoAvatar',
        'persona' => 'Agent without avatar specified',
    ]));

    $agent = Agent::query()->where('slug', 'noavatar')->first();
    expect($agent->avatar)->toBe('🤖');
});

it('enforces max 10 agents', function () {
    Agent::factory()->count(10)->create();

    $result = $this->tool->handle(new Request([
        'action' => 'create',
        'name' => 'OneMore',
        'persona' => 'Should not be created',
    ]));

    expect((string) $result)->toContain('Maximum of 10 agents reached')
        ->and(Agent::query()->count())->toBe(10);
});

it('prevents duplicate agent names', function () {
    Agent::factory()->create(['name' => 'FitCoach', 'slug' => 'fitcoach']);

    $result = $this->tool->handle(new Request([
        'action' => 'create',
        'name' => 'FitCoach',
        'persona' => 'Duplicate',
    ]));

    expect((string) $result)->toContain('already exists')
        ->and(Agent::query()->where('slug', 'fitcoach')->count())->toBe(1);
});

it('requires name and persona for create', function () {
    $result = $this->tool->handle(new Request([
        'action' => 'create',
        'name' => '',
        'persona' => '',
    ]));

    expect((string) $result)->toContain('Both name and persona are required');
});

it('updates an existing agent', function () {
    $agent = Agent::factory()->create(['name' => 'OldName', 'slug' => 'oldname']);

    $result = $this->tool->handle(new Request([
        'action' => 'update',
        'agent_id' => $agent->id,
        'name' => 'NewName',
        'persona' => 'Updated persona',
        'avatar' => '🏃',
    ]));

    $agent->refresh();
    expect((string) $result)->toContain('Updated agent "NewName"')
        ->and($agent->name)->toBe('NewName')
        ->and($agent->slug)->toBe('newname')
        ->and($agent->persona)->toBe('Updated persona')
        ->and($agent->avatar)->toBe('🏃');
});

it('returns error for update without agent_id', function () {
    $result = $this->tool->handle(new Request([
        'action' => 'update',
        'name' => 'Nope',
    ]));

    expect((string) $result)->toContain('agent_id is required');
});

it('lists active agents', function () {
    Agent::factory()->create(['name' => 'Alpha', 'avatar' => '🅰️', 'is_active' => true]);
    Agent::factory()->create(['name' => 'Beta', 'avatar' => '🅱️', 'is_active' => true]);
    Agent::factory()->create(['name' => 'Inactive', 'is_active' => false]);

    $result = $this->tool->handle(new Request(['action' => 'list']));

    expect((string) $result)->toContain('Alpha')
        ->and((string) $result)->toContain('Beta')
        ->and((string) $result)->not->toContain('Inactive')
        ->and((string) $result)->toContain('2/10');
});

it('returns message when no agents exist', function () {
    $result = $this->tool->handle(new Request(['action' => 'list']));

    expect((string) $result)->toContain('No active agents found');
});

it('deletes an agent', function () {
    $agent = Agent::factory()->create(['name' => 'ToDelete']);
    Skill::factory()->create(['slug' => 'test-skill', 'is_active' => true]);
    $agent->skills()->attach(Skill::query()->first());

    $result = $this->tool->handle(new Request([
        'action' => 'delete',
        'agent_id' => $agent->id,
    ]));

    expect((string) $result)->toContain('Deleted agent "ToDelete"')
        ->and(Agent::query()->find($agent->id))->toBeNull();
});

it('returns error for delete without agent_id', function () {
    $result = $this->tool->handle(new Request([
        'action' => 'delete',
    ]));

    expect((string) $result)->toContain('agent_id is required');
});

it('returns error for unknown action', function () {
    $result = $this->tool->handle(new Request(['action' => 'fly']));

    expect((string) $result)->toContain("Unknown action 'fly'");
});

it('auto-suggests skills from persona when none provided', function () {
    $this->seed(\Database\Seeders\SkillSeeder::class);

    $result = $this->tool->handle(new Request([
        'action' => 'create',
        'name' => 'FitCoach',
        'persona' => 'A fitness coach who helps with workouts and exercise routines',
    ]));

    $agent = Agent::query()->where('slug', 'fitcoach')->first();
    expect($agent->skills)->not->toBeEmpty()
        ->and($agent->skills->pluck('slug')->toArray())->toContain('health-fitness')
        ->and((string) $result)->toContain('Health & Fitness');
});

it('does not auto-suggest when skills are explicitly provided', function () {
    Skill::factory()->create(['slug' => 'writing-coach', 'name' => 'Writing Coach', 'is_active' => true]);
    $this->seed(\Database\Seeders\SkillSeeder::class);

    $this->tool->handle(new Request([
        'action' => 'create',
        'name' => 'FitWriter',
        'persona' => 'A fitness and writing coach',
        'suggested_skills' => ['writing-coach'],
    ]));

    $agent = Agent::query()->where('slug', 'fitwriter')->first();
    $slugs = $agent->skills->pluck('slug')->toArray();
    expect($slugs)->toContain('writing-coach')
        ->and($slugs)->not->toContain('health-fitness');
});
