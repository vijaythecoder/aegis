<?php

use App\Models\Skill;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a skill with factory', function () {
    $skill = Skill::factory()->create();

    expect($skill)->toBeInstanceOf(Skill::class);
    expect($skill->id)->toBeGreaterThan(0);
    expect($skill->name)->not->toBeEmpty();
    expect($skill->slug)->not->toBeEmpty();
    expect($skill->description)->not->toBeEmpty();
    expect($skill->instructions)->not->toBeEmpty();
    expect($skill->is_active)->toBeTrue();
});

it('casts metadata as array', function () {
    $skill = Skill::factory()->create([
        'metadata' => ['tags' => ['ai', 'productivity'], 'rating' => 4.5],
    ]);

    expect($skill->metadata)->toBeArray();
    expect($skill->metadata['tags'])->toContain('ai');
    expect($skill->metadata['rating'])->toBe(4.5);
});

it('casts is_active as boolean', function () {
    $skill = Skill::factory()->create(['is_active' => false]);

    expect($skill->is_active)->toBeFalse();
});

it('generates unique slug', function () {
    $skill1 = Skill::factory()->create(['name' => 'Data Analysis']);
    $skill2 = Skill::factory()->create(['name' => 'Code Review']);

    expect($skill1->slug)->not->toBe($skill2->slug);
});

it('has default source as user_created', function () {
    $skill = Skill::factory()->create();

    expect($skill->source)->toBe('user_created');
});

it('has default version as 1.0', function () {
    $skill = Skill::factory()->create();

    expect($skill->version)->toBe('1.0');
});

it('has builtIn state', function () {
    $skill = Skill::factory()->builtIn()->create();

    expect($skill->source)->toBe('built_in');
});

it('has userCreated state', function () {
    $skill = Skill::factory()->userCreated()->create();

    expect($skill->source)->toBe('user_created');
});

it('has marketplace state', function () {
    $skill = Skill::factory()->marketplace()->create();

    expect($skill->source)->toBe('marketplace');
});

it('has agents relationship', function () {
    $skill = Skill::factory()->create();

    expect($skill->agents())->not->toBeNull();
});
