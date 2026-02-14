<?php

use App\Plugins\PluginManifest;
use App\Plugins\PluginManager;
use App\Tools\ToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

it('discovers plugins in plugins directory', function () {
    $pluginsPath = base_path('storage/framework/testing/plugins-discover');
    File::deleteDirectory($pluginsPath);
    File::makeDirectory($pluginsPath.'/sample-plugin/src', 0755, true);

    File::put($pluginsPath.'/sample-plugin/plugin.json', json_encode([
        'name' => 'sample-plugin',
        'version' => '1.0.0',
        'description' => 'Sample plugin for discovery',
        'author' => 'Tests',
        'permissions' => ['none'],
        'provider' => 'SamplePlugin\\SampleServiceProvider',
        'tools' => ['sample_tool'],
        'autoload' => [
            'psr-4' => [
                'SamplePlugin\\' => 'src/',
            ],
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    $manager = new PluginManager(app(), $pluginsPath);
    $plugins = $manager->discover();

    expect($plugins)->toHaveKey('sample-plugin')
        ->and($plugins['sample-plugin']->name)->toBe('sample-plugin');
});

it('loads a valid manifest successfully', function () {
    $manifest = PluginManifest::fromPath(base_path('plugins/aegis-calculator'));

    expect($manifest->name)->toBe('aegis-calculator')
        ->and($manifest->version)->toBe('1.0.0')
        ->and($manifest->description)->toBe('A simple calculator tool for Aegis')
        ->and($manifest->tools)->toContain('calculator')
        ->and($manifest->isValid())->toBeTrue();
});

it('rejects invalid manifest with clear errors', function () {
    $pluginsPath = base_path('storage/framework/testing/plugins-invalid');
    File::deleteDirectory($pluginsPath);
    File::makeDirectory($pluginsPath.'/broken/src', 0755, true);

    File::put($pluginsPath.'/broken/plugin.json', json_encode([
        'name' => '',
        'version' => '1.0.0',
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

    expect(fn () => PluginManifest::fromPath($pluginsPath.'/broken'))
        ->toThrow(InvalidArgumentException::class, 'Invalid plugin manifest');
});

it('registers plugin tools with the tool registry', function () {
    $manager = app(PluginManager::class);

    $manager->load('aegis-calculator');

    $registry = app(ToolRegistry::class);

    expect($registry->names())->toContain('calculator');
});

it('supports plugin enable and disable persistence', function () {
    $manager = app(PluginManager::class);

    $manager->enable('aegis-calculator');
    expect($manager->isEnabled('aegis-calculator'))->toBeTrue();

    $manager->disable('aegis-calculator');
    expect($manager->isEnabled('aegis-calculator'))->toBeFalse();
});

it('executes calculator plugin end to end', function () {
    $manager = app(PluginManager::class);
    $manager->load('aegis-calculator');

    $registry = app(ToolRegistry::class);
    $tool = $registry->get('calculator');
    $result = $tool?->execute(['expression' => '2+2']);

    expect($result)->not->toBeNull()
        ->and($result->success)->toBeTrue()
        ->and($result->output)->toBe(4);
});

it('scaffolds plugin structure from artisan command', function () {
    $pluginName = 'test-plugin';
    $pluginPath = base_path('plugins/'.$pluginName);
    File::deleteDirectory($pluginPath);

    test()->artisan('aegis:plugin:create '.$pluginName)
        ->assertExitCode(0);

    expect(File::exists($pluginPath.'/plugin.json'))->toBeTrue()
        ->and(File::isDirectory($pluginPath.'/src'))->toBeTrue();

    $manifest = json_decode((string) File::get($pluginPath.'/plugin.json'), true);

    expect($manifest)->toBeArray()
        ->and($manifest)->toHaveKeys(['name', 'version', 'description', 'author', 'permissions', 'provider', 'tools', 'autoload']);
});
