<?php

use App\Desktop\UpdateService;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

it('returns current version from nativephp config', function () {
    config()->set('nativephp.version', '2.5.0');

    expect(app(UpdateService::class)->currentVersion())->toBe('2.5.0');
});

it('detects available update when github release is newer', function () {
    config()->set('nativephp.version', '1.0.0');
    config()->set('nativephp.updater.providers.github.owner', 'AegisApp');
    config()->set('nativephp.updater.providers.github.repo', 'aegis');

    Http::fake([
        'https://api.github.com/repos/AegisApp/aegis/releases/latest' => Http::response([
            'tag_name' => 'v1.1.0',
            'body' => 'Bug fixes and performance improvements',
            'html_url' => 'https://github.com/AegisApp/aegis/releases/tag/v1.1.0',
            'published_at' => '2026-02-13T00:00:00Z',
        ], 200),
    ]);

    $update = app(UpdateService::class)->checkForUpdate();

    expect($update)->not->toBeNull()
        ->and($update['current_version'])->toBe('1.0.0')
        ->and($update['latest_version'])->toBe('1.1.0')
        ->and($update['release_notes'])->toContain('Bug fixes');
});

it('returns null when current version is latest', function () {
    config()->set('nativephp.version', '2.0.0');
    config()->set('nativephp.updater.providers.github.owner', 'AegisApp');
    config()->set('nativephp.updater.providers.github.repo', 'aegis');

    Http::fake([
        'https://api.github.com/repos/AegisApp/aegis/releases/latest' => Http::response([
            'tag_name' => 'v1.9.0',
            'body' => 'Old release',
        ], 200),
    ]);

    expect(app(UpdateService::class)->checkForUpdate())->toBeNull();
});

it('persists auto update enabled setting', function () {
    $service = app(UpdateService::class);

    expect($service->isAutoUpdateEnabled())->toBeTrue();

    $service->setAutoUpdateEnabled(false);
    expect($service->isAutoUpdateEnabled())->toBeFalse();

    $service->setAutoUpdateEnabled(true);
    expect($service->isAutoUpdateEnabled())->toBeTrue();
});

it('persists update channel setting', function () {
    $service = app(UpdateService::class);

    expect($service->updateChannel())->toBe('stable');

    $service->setUpdateChannel('beta');
    expect($service->updateChannel())->toBe('beta');

    $service->setUpdateChannel('invalid');
    expect($service->updateChannel())->toBe('stable');
});

it('checks daily update interval correctly', function () {
    $service = app(UpdateService::class);
    config()->set('aegis.update_check_interval', 86400);

    expect($service->shouldCheckForUpdates())->toBeTrue();

    $service->recordCheckTimestamp();
    expect($service->shouldCheckForUpdates())->toBeFalse();

    Setting::query()->updateOrCreate(
        ['group' => 'general', 'key' => 'last_update_check'],
        ['value' => (string) (now()->timestamp - 90000)],
    );
    expect($service->shouldCheckForUpdates())->toBeTrue();
});

it('skips update check when auto update is disabled', function () {
    $service = app(UpdateService::class);
    $service->setAutoUpdateEnabled(false);

    expect($service->shouldCheckForUpdates())->toBeFalse();
});

it('fetches beta releases from releases list endpoint', function () {
    config()->set('nativephp.version', '1.0.0');
    config()->set('nativephp.updater.providers.github.owner', 'AegisApp');
    config()->set('nativephp.updater.providers.github.repo', 'aegis');

    Http::fake([
        'https://api.github.com/repos/AegisApp/aegis/releases' => Http::response([
            ['tag_name' => 'v1.2.0-beta.1', 'body' => 'Beta release', 'prerelease' => true],
            ['tag_name' => 'v1.1.0', 'body' => 'Stable', 'prerelease' => false],
        ], 200),
    ]);

    $service = app(UpdateService::class);
    $service->setUpdateChannel('beta');

    $update = $service->checkForUpdate();

    expect($update)->not->toBeNull()
        ->and($update['latest_version'])->toBe('1.2.0-beta.1');
});

it('returns false for native update when autoupdater unavailable', function () {
    $service = app(UpdateService::class);

    expect($service->triggerNativeUpdate())->toBeFalse()
        ->and($service->triggerNativeInstall())->toBeFalse();
});
