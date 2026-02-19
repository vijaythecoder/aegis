<?php

use App\Livewire\SkillSettings;
use App\Models\Agent;
use App\Models\Skill;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders skill settings component', function () {
    Livewire::test(SkillSettings::class)->assertStatus(200);
});

it('shows built-in skills as read-only', function () {
    Skill::factory()->create([
        'name' => 'Fitness Knowledge',
        'source' => 'built_in',
        'is_active' => true,
    ]);

    Livewire::test(SkillSettings::class)
        ->assertSee('Fitness Knowledge')
        ->assertSee('Built-in Skills');
});

it('shows custom skills separately', function () {
    Skill::factory()->create([
        'name' => 'My Custom Skill',
        'source' => 'user_created',
    ]);

    Livewire::test(SkillSettings::class)
        ->assertSee('My Custom Skill')
        ->assertSee('Custom Skills');
});

it('creates a custom skill', function () {
    Livewire::test(SkillSettings::class)
        ->call('newSkill')
        ->set('name', 'Tax Knowledge')
        ->set('description', 'Tax filing expertise')
        ->set('instructions', 'You are an expert in tax preparation...')
        ->set('category', 'finance')
        ->call('createSkill');

    expect(Skill::where('slug', 'tax-knowledge')->exists())->toBeTrue();
    expect(Skill::where('slug', 'tax-knowledge')->first()->source)->toBe('user_created');
});

it('validates required fields on create', function () {
    Livewire::test(SkillSettings::class)
        ->call('newSkill')
        ->set('name', '')
        ->set('instructions', '')
        ->call('createSkill')
        ->assertHasErrors(['name', 'instructions']);
});

it('prevents editing built-in skills', function () {
    $skill = Skill::factory()->create([
        'name' => 'Built-in Skill',
        'source' => 'built_in',
    ]);

    Livewire::test(SkillSettings::class)
        ->call('editSkill', $skill->id)
        ->assertSet('showForm', false)
        ->assertSet('editingSkillId', null);
});

it('edits a custom skill', function () {
    $skill = Skill::factory()->create([
        'name' => 'Old Skill Name',
        'source' => 'user_created',
    ]);

    Livewire::test(SkillSettings::class)
        ->call('editSkill', $skill->id)
        ->set('name', 'Updated Skill Name')
        ->call('updateSkill');

    expect($skill->fresh()->name)->toBe('Updated Skill Name');
});

it('deletes a custom skill', function () {
    $skill = Skill::factory()->create([
        'name' => 'Delete Me',
        'source' => 'user_created',
    ]);

    Livewire::test(SkillSettings::class)
        ->call('deleteSkill', $skill->id);

    expect(Skill::where('name', 'Delete Me')->exists())->toBeFalse();
});

it('prevents deleting built-in skills', function () {
    $skill = Skill::factory()->create([
        'name' => 'Protected Skill',
        'source' => 'built_in',
    ]);

    Livewire::test(SkillSettings::class)
        ->call('deleteSkill', $skill->id);

    expect(Skill::where('name', 'Protected Skill')->exists())->toBeTrue();
});

it('validates max length on instructions', function () {
    Livewire::test(SkillSettings::class)
        ->call('newSkill')
        ->set('name', 'Long Skill')
        ->set('instructions', str_repeat('a', 15001))
        ->call('createSkill')
        ->assertHasErrors(['instructions']);
});

it('shows skill detail view when viewing a skill', function () {
    $skill = Skill::factory()->create([
        'name' => 'Detail Skill',
        'instructions' => 'Detailed instructions here.',
        'source' => 'built_in',
        'is_active' => true,
    ]);

    Livewire::test(SkillSettings::class)
        ->call('viewSkill', $skill->id)
        ->assertSee('Detail Skill')
        ->assertSee('Detailed instructions here.');
});

it('detaches agents when deleting a custom skill', function () {
    $skill = Skill::factory()->create(['source' => 'user_created']);
    $agent = Agent::factory()->create();
    $agent->skills()->attach($skill);

    expect($agent->skills()->count())->toBe(1);

    Livewire::test(SkillSettings::class)
        ->call('deleteSkill', $skill->id);

    expect(Skill::find($skill->id))->toBeNull();
    expect($agent->skills()->count())->toBe(0);
});
