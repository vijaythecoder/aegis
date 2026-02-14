<?php

use App\Desktop\Contracts\DesktopBridge;
use App\Desktop\ElectronDesktopBridge;

use Illuminate\Foundation\Application;

it('boots the application successfully', function () {
    expect(app())->toBeInstanceOf(Application::class);
});

it('has required application directories', function () {
    $dirs = [
        app_path('Agent'),
        app_path('Desktop'),
        app_path('Desktop/Contracts'),
        app_path('Memory'),
        app_path('Security'),
        app_path('Tools'),
        app_path('Livewire'),
    ];

    foreach ($dirs as $dir) {
        expect(is_dir($dir))->toBeTrue("Directory missing: {$dir}");
    }
});

it('resolves DesktopBridge binding', function () {
    $bridge = app(DesktopBridge::class);

    expect($bridge)
        ->toBeInstanceOf(ElectronDesktopBridge::class)
        ->and($bridge)
        ->toBe(app(DesktopBridge::class));
});
