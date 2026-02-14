<?php

use App\Desktop\Contracts\DesktopBridge;
use App\Desktop\ElectronDesktopBridge;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('renders the home route successfully', function () {
    Setting::query()->create(['group' => 'app', 'key' => 'onboarding_completed', 'value' => 'true', 'is_encrypted' => false]);

    $response = $this->get('/');

    $response->assertRedirect('/chat');
});

it('home route has the named route "home"', function () {
    Setting::query()->create(['group' => 'app', 'key' => 'onboarding_completed', 'value' => 'true', 'is_encrypted' => false]);

    $response = $this->get(route('home'));

    $response->assertRedirect('/chat');
});

it('renders the layout with sidebar', function () {
    Setting::query()->create(['group' => 'app', 'key' => 'onboarding_completed', 'value' => 'true', 'is_encrypted' => false]);

    $response = $this->get('/chat');

    $response->assertSee('Aegis', false);
    $response->assertSee('New Chat', false);
    $response->assertSee('Settings', false);
    $response->assertSee('Conversations', false);
});

it('renders the home welcome content', function () {
    $response = $this->get('/onboarding');

    $response->assertSee('Welcome to', false);
    $response->assertSee('AI under your Aegis', false);
});

it('layout includes vite asset references', function () {
    Setting::query()->create(['group' => 'app', 'key' => 'onboarding_completed', 'value' => 'true', 'is_encrypted' => false]);

    $response = $this->get('/chat');
    $content = $response->getContent();

    expect($content)->toMatch('/\.(css|js)/')
        ->and($content)->toContain('<script')
        ->and($content)->toContain('<link');
});

it('resolves DesktopBridge from container', function () {
    $bridge = app(DesktopBridge::class);

    expect($bridge)->toBeInstanceOf(ElectronDesktopBridge::class);
});

it('uses aegis config values in layout', function () {
    Setting::query()->create(['group' => 'app', 'key' => 'onboarding_completed', 'value' => 'true', 'is_encrypted' => false]);

    config(['aegis.name' => 'TestAegis']);

    $response = $this->get('/chat');

    $response->assertSee('TestAegis', false);
});

it('layout has Alpine.js sidebar toggle data', function () {
    Setting::query()->create(['group' => 'app', 'key' => 'onboarding_completed', 'value' => 'true', 'is_encrypted' => false]);

    $response = $this->get('/chat');
    $content = $response->getContent();

    expect($content)->toContain('x-data')
        ->and($content)->toContain('sidebarOpen');
});
