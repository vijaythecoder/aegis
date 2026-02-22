<?php

use App\Models\Memory;
use App\Models\Skill;
use App\Services\SkillGeneratorService;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->service = app(SkillGeneratorService::class);
});

it('returns empty patterns when no memories exist', function () {
    $patterns = $this->service->detectPatterns();

    expect($patterns)->toBeEmpty();
});

it('detects patterns above threshold from memory keys', function () {
    for ($i = 0; $i < 6; $i++) {
        Memory::factory()->create([
            'key' => "project.fitness.goal{$i}",
            'value' => "Track fitness progress item {$i}",
        ]);
    }

    $patterns = $this->service->detectPatterns();

    expect($patterns)->toHaveKey('fitness');
    expect($patterns['fitness'])->toBeGreaterThanOrEqual(5);
});

it('excludes topics below threshold', function () {
    for ($i = 0; $i < 3; $i++) {
        Memory::factory()->create([
            'key' => "project.rare.item{$i}",
            'value' => "Some rare topic {$i}",
        ]);
    }

    $patterns = $this->service->detectPatterns();

    expect($patterns)->not->toHaveKey('rare');
});

it('excludes topics that already have a skill', function () {
    Skill::factory()->create(['slug' => 'cooking']);

    for ($i = 0; $i < 7; $i++) {
        Memory::factory()->create([
            'key' => "preference.cooking.style{$i}",
            'value' => "Cooking preference {$i}",
        ]);
    }

    $patterns = $this->service->detectPatterns();

    expect($patterns)->not->toHaveKey('cooking');
});

it('excludes stop words from pattern detection', function () {
    for ($i = 0; $i < 10; $i++) {
        Memory::factory()->create([
            'key' => "user.item{$i}",
            'value' => "the value {$i}",
        ]);
    }

    $patterns = $this->service->detectPatterns();

    expect($patterns)->not->toHaveKey('user')
        ->and($patterns)->not->toHaveKey('the');
});

it('proposes a skill with correct attributes', function () {
    for ($i = 0; $i < 6; $i++) {
        Memory::factory()->create([
            'key' => "project.gardening.task{$i}",
            'value' => "Gardening tip number {$i}",
        ]);
    }

    $skill = $this->service->proposeSkill('gardening');

    expect($skill)->not->toBeNull()
        ->and($skill->name)->toBe('Gardening')
        ->and($skill->slug)->toBe('gardening')
        ->and($skill->source)->toBe('ai_generated')
        ->and($skill->is_active)->toBeFalse()
        ->and($skill->category)->toBe('personal')
        ->and($skill->instructions)->not->toBeEmpty();
});

it('does not create duplicate skills', function () {
    Skill::factory()->create(['slug' => 'photography']);

    $skill = $this->service->proposeSkill('photography');

    expect($skill)->toBeNull();
    expect(Skill::query()->where('slug', 'photography')->count())->toBe(1);
});

it('uses fallback content when agent fails', function () {
    for ($i = 0; $i < 3; $i++) {
        Memory::factory()->create([
            'key' => "hobby.painting.tip{$i}",
            'value' => "Painting technique {$i}",
        ]);
    }

    $content = $this->service->generateSkillContent('painting');

    expect($content)->toContain('painting');
});
