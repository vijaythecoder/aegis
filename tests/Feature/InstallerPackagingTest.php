<?php

it('has aegis app id configured for nativephp', function () {
    expect(config('nativephp.app_id'))->toBe('com.aegis.app');
});

it('has aegis deep link scheme configured', function () {
    expect(config('nativephp.deeplink_scheme'))->toBe('aegis');
});

it('has aegis branding in nativephp description', function () {
    expect(config('nativephp.description'))->toContain('Aegis');
});

it('has github as default updater provider', function () {
    expect(config('nativephp.updater.default'))->toBe('github');
});

it('has updater enabled by default', function () {
    expect(config('nativephp.updater.enabled'))->toBeTrue();
});

it('has vite build in prebuild scripts', function () {
    expect(config('nativephp.prebuild'))->toContain('npm run build');
});

it('has github actions build workflow', function () {
    expect(file_exists(base_path('.github/workflows/build.yml')))->toBeTrue();
});

it('has aegis copyright in nativephp config', function () {
    expect(config('nativephp.copyright'))->toContain('MIT');
});
