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
