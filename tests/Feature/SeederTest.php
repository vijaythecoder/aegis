<?php

use App\Models\Agent;
use App\Models\Skill;
use Database\Seeders\DefaultAgentSeeder;
use Database\Seeders\SkillSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('seeds 7 built-in skills', function () {
    $this->seed(SkillSeeder::class);

    expect(Skill::where('source', 'built_in')->count())->toBe(7);
});

it('seeds skills with correct slugs', function () {
    $this->seed(SkillSeeder::class);

    $expectedSlugs = [
        'research-assistant',
        'writing-coach',
        'data-analyst',
        'schedule-manager',
        'finance-tracker',
        'health-fitness',
        'learning-guide',
    ];

    foreach ($expectedSlugs as $slug) {
        expect(Skill::where('slug', $slug)->exists())->toBeTrue("Skill with slug '{$slug}' not found");
    }
});

it('seeds skills idempotently', function () {
    $this->seed(SkillSeeder::class);
    $this->seed(SkillSeeder::class);

    expect(Skill::where('source', 'built_in')->count())->toBe(7);
});

it('seeds default aegis agent', function () {
    $this->seed(DefaultAgentSeeder::class);

    $agent = Agent::where('slug', 'aegis')->first();
    expect($agent)->not->toBeNull()
        ->and($agent->name)->toBe('Aegis')
        ->and($agent->avatar)->toBe('🛡️')
        ->and($agent->is_active)->toBeTrue();
});

it('seeds default agent idempotently', function () {
    $this->seed(DefaultAgentSeeder::class);
    $this->seed(DefaultAgentSeeder::class);

    expect(Agent::where('slug', 'aegis')->count())->toBe(1);
});

it('seeds skills with non-empty instructions', function () {
    $this->seed(SkillSeeder::class);

    Skill::where('source', 'built_in')->each(function ($skill) {
        expect(strlen($skill->instructions))->toBeGreaterThan(50, "Skill '{$skill->slug}' has too-short instructions");
    });
});
