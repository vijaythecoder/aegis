<?php

use App\Livewire\OnboardingWizard;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

it('renders the onboarding page', function () {
    $response = test()->get('/onboarding');

    $response->assertStatus(200);
    $response->assertSeeLivewire(OnboardingWizard::class);
});

it('starts at step 1 (welcome)', function () {
    Livewire::test(OnboardingWizard::class)
        ->assertSet('currentStep', 1)
        ->assertSee('Welcome to')
        ->assertSee('Get Started');
});

it('navigates from step 1 to step 2', function () {
    Livewire::test(OnboardingWizard::class)
        ->assertSet('currentStep', 1)
        ->call('nextStep')
        ->assertSet('currentStep', 2);
});

it('navigates back from step 2 to step 1', function () {
    Livewire::test(OnboardingWizard::class)
        ->set('currentStep', 2)
        ->call('previousStep')
        ->assertSet('currentStep', 1);
});

it('cannot go back from step 1', function () {
    Livewire::test(OnboardingWizard::class)
        ->assertSet('currentStep', 1)
        ->call('previousStep')
        ->assertSet('currentStep', 1);
});

it('cannot exceed step 4', function () {
    Livewire::test(OnboardingWizard::class)
        ->set('currentStep', 4)
        ->call('nextStep')
        ->assertSet('currentStep', 4);
});

it('can navigate through all 4 steps', function () {
    Livewire::test(OnboardingWizard::class)
        ->assertSet('currentStep', 1)
        ->call('nextStep')
        ->assertSet('currentStep', 2)
        ->call('nextStep')
        ->assertSet('currentStep', 3)
        ->call('nextStep')
        ->assertSet('currentStep', 4);
});

it('shows provider selection on step 2', function () {
    Livewire::test(OnboardingWizard::class)
        ->set('currentStep', 2)
        ->assertSee('Anthropic')
        ->assertSee('OpenAI');
});

it('shows security preview on step 3', function () {
    Livewire::test(OnboardingWizard::class)
        ->set('currentStep', 3)
        ->assertSee('auto-allowed')
        ->assertSee('approval');
});

it('shows ready state on step 4', function () {
    Livewire::test(OnboardingWizard::class)
        ->set('currentStep', 4)
        ->assertSee('all set')
        ->assertSee('Start Using Aegis');
});

it('can skip to completion from any step', function () {
    Livewire::test(OnboardingWizard::class)
        ->assertSet('currentStep', 1)
        ->call('skip')
        ->assertRedirect('/');
});

it('stores onboarding_completed setting on complete', function () {
    Livewire::test(OnboardingWizard::class)
        ->set('currentStep', 4)
        ->call('complete');

    $setting = Setting::query()
        ->where('group', 'app')
        ->where('key', 'onboarding_completed')
        ->first();

    expect($setting)->not->toBeNull()
        ->and($setting->value)->toBe('true');
});

it('stores onboarding_completed setting on skip', function () {
    Livewire::test(OnboardingWizard::class)
        ->call('skip');

    $setting = Setting::query()
        ->where('group', 'app')
        ->where('key', 'onboarding_completed')
        ->first();

    expect($setting)->not->toBeNull()
        ->and($setting->value)->toBe('true');
});

it('redirects to home after completion', function () {
    Livewire::test(OnboardingWizard::class)
        ->set('currentStep', 4)
        ->call('complete')
        ->assertRedirect('/');
});

it('redirects to onboarding if not completed', function () {
    $response = test()->get('/');

    $response->assertRedirect('/onboarding');
});

it('does not redirect to onboarding if already completed', function () {
    Setting::query()->create([
        'group' => 'app',
        'key' => 'onboarding_completed',
        'value' => 'true',
        'is_encrypted' => false,
    ]);

    $response = test()->get('/');

    $response->assertRedirect('/chat');
});

it('redirects away from onboarding if already completed', function () {
    Setting::query()->create([
        'group' => 'app',
        'key' => 'onboarding_completed',
        'value' => 'true',
        'is_encrypted' => false,
    ]);

    $response = test()->get('/onboarding');

    $response->assertRedirect('/');
});

it('saves selected provider on step 2', function () {
    Livewire::test(OnboardingWizard::class)
        ->set('currentStep', 2)
        ->set('selectedProvider', 'anthropic')
        ->set('apiKey', 'sk-ant-ABCDEFGHIJKLMNOPQRSTUVWXYZ1234')
        ->call('saveProvider')
        ->assertHasNoErrors();

    $setting = Setting::query()
        ->where('group', 'app')
        ->where('key', 'default_provider')
        ->first();

    expect($setting)->not->toBeNull()
        ->and($setting->value)->toBe('anthropic');
});

it('validates api key format before saving', function () {
    Livewire::test(OnboardingWizard::class)
        ->set('currentStep', 2)
        ->set('selectedProvider', 'anthropic')
        ->set('apiKey', 'invalid-key')
        ->call('saveProvider')
        ->assertHasErrors(['apiKey']);
});

it('does not require api key for ollama', function () {
    Livewire::test(OnboardingWizard::class)
        ->set('currentStep', 2)
        ->set('selectedProvider', 'ollama')
        ->call('saveProvider')
        ->assertHasNoErrors();

    $setting = Setting::query()
        ->where('group', 'app')
        ->where('key', 'default_provider')
        ->first();

    expect($setting)->not->toBeNull()
        ->and($setting->value)->toBe('ollama');
});

it('has skip available on every step', function () {
    foreach ([1, 2, 3, 4] as $step) {
        Livewire::test(OnboardingWizard::class)
            ->set('currentStep', $step)
            ->assertSee("Skip setup");
    }
});
