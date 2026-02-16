<?php

use App\Providers\NativeAppServiceProvider;
use Native\Laravel\Facades\ChildProcess;

test('native app service provider starts scheduler as persistent child process', function () {
    $fake = ChildProcess::fake();

    $provider = new NativeAppServiceProvider;
    $provider->boot();

    $fake->assertArtisan(function (array|string $cmd, string $alias, ?array $env, ?bool $persistent, ?array $iniSettings) {
        return $cmd === ['schedule:work']
            && $alias === 'scheduler'
            && $persistent === true;
    });
});

test('schedule:work command is registered in console routes', function () {
    $this->artisan('schedule:list')
        ->assertSuccessful();
});

test('aegis:proactive:run is scheduled every minute', function () {
    $this->artisan('schedule:list')
        ->expectsOutputToContain('aegis:proactive:run')
        ->assertSuccessful();
});

test('native app service provider starts telegram poller when bot token configured', function () {
    config(['aegis.messaging.telegram.bot_token' => 'test-token-123']);

    $fake = ChildProcess::fake();

    $provider = new NativeAppServiceProvider;
    $provider->boot();

    $fake->assertArtisan(function (array|string $cmd, string $alias, ?array $env, ?bool $persistent, ?array $iniSettings) {
        return $cmd === ['telegram:poll']
            && $alias === 'telegram-poller'
            && $persistent === true;
    });
});

test('native app service provider does not start telegram poller without bot token', function () {
    config(['aegis.messaging.telegram.bot_token' => '']);

    $fake = ChildProcess::fake();

    $provider = new NativeAppServiceProvider;
    $provider->boot();

    $aliases = array_column($fake->artisans, 'alias');
    expect($aliases)->toContain('scheduler')
        ->not->toContain('telegram-poller');
});
