<?php

use App\Marketplace\MarketplaceService;
use App\Marketplace\PluginRegistry;
use App\Models\MarketplacePlugin;
use App\Plugins\PluginManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config()->set('aegis.marketplace.registry_url', 'https://market.test/api');
    config()->set('aegis.marketplace.enabled', true);
    config()->set('aegis.marketplace.cache_ttl', 3600);

    File::deleteDirectory(base_path('plugins/market-calc'));
    File::deleteDirectory(base_path('plugins/update-check'));
    File::deleteDirectory(base_path('storage/framework/testing/market-calc-source'));
    File::deleteDirectory(base_path('storage/framework/testing/publish-source'));
});

it('syncs marketplace plugins from registry into local cache', function () {
    Http::fake([
        'market.test/api/plugins' => Http::response([
            'data' => [
                [
                    'name' => 'market-calc',
                    'version' => '1.2.0',
                    'description' => 'Calculator plugin',
                    'author' => 'Aegis',
                    'downloads' => 1200,
                    'rating' => 4.8,
                    'trust_tier' => 'verified',
                    'manifest_url' => 'https://market.test/manifests/market-calc.json',
                    'checksum' => 'abc123',
                ],
            ],
        ], 200),
    ]);

    $plugins = app(PluginRegistry::class)->sync(true);

    expect($plugins)->toHaveCount(1)
        ->and(MarketplacePlugin::query()->where('name', 'market-calc')->exists())->toBeTrue()
        ->and(MarketplacePlugin::query()->where('name', 'market-calc')->value('trust_tier'))->toBe('verified');
});

it('searches cached marketplace plugins by query', function () {
    MarketplacePlugin::query()->create([
        'name' => 'market-calc',
        'version' => '1.0.0',
        'description' => 'Powerful calculator',
        'author' => 'Aegis',
        'downloads' => 50,
        'rating' => 4.5,
        'trust_tier' => 'community',
        'manifest_url' => 'https://market.test/manifests/market-calc.json',
        'checksum' => 'sum-a',
    ]);

    MarketplacePlugin::query()->create([
        'name' => 'report-builder',
        'version' => '1.0.0',
        'description' => 'Build reports',
        'author' => 'Aegis',
        'downloads' => 5,
        'rating' => 3.0,
        'trust_tier' => 'unverified',
        'manifest_url' => 'https://market.test/manifests/report-builder.json',
        'checksum' => 'sum-b',
    ]);

    $results = app(PluginRegistry::class)->search('calc');

    expect($results)->toHaveCount(1)
        ->and($results->first()->name)->toBe('market-calc');
});

it('installs a marketplace plugin from remote metadata', function () {
    File::makeDirectory(base_path('storage/framework/testing/market-calc-source/src'), 0755, true);
    File::put(base_path('storage/framework/testing/market-calc-source/plugin.json'), json_encode([
        'name' => 'market-calc',
        'version' => '1.0.0',
        'description' => 'Calculator plugin',
        'author' => 'Tests',
        'permissions' => ['none'],
        'provider' => 'MarketCalc\\ServiceProvider',
        'tools' => [],
        'autoload' => [
            'psr-4' => [
                'MarketCalc\\' => 'src/',
            ],
        ],
    ], JSON_UNESCAPED_SLASHES));

    Http::fake([
        'market.test/api/plugins' => Http::response([
            'data' => [[
                'name' => 'market-calc',
                'version' => '1.0.0',
                'description' => 'Calculator plugin',
                'author' => 'Tests',
                'downloads' => 1,
                'rating' => 5,
                'trust_tier' => 'verified',
                'manifest_url' => 'https://market.test/manifests/market-calc.json',
                'checksum' => 'abc',
            ]],
        ], 200),
        'market.test/api/plugins/market-calc/download' => Http::response([
            'source' => base_path('storage/framework/testing/market-calc-source'),
        ], 200),
    ]);

    $manifest = app(MarketplaceService::class)->install('market-calc');
    $manager = app(PluginManager::class);

    expect($manifest->name)->toBe('market-calc')
        ->and(File::isDirectory(base_path('plugins/market-calc')))->toBeTrue()
        ->and($manager->isEnabled('market-calc'))->toBeTrue();
});

it('reports available updates for installed plugins', function () {
    File::makeDirectory(base_path('plugins/update-check/src'), 0755, true);
    File::put(base_path('plugins/update-check/plugin.json'), json_encode([
        'name' => 'update-check',
        'version' => '1.0.0',
        'description' => 'Update test plugin',
        'author' => 'Tests',
        'permissions' => ['none'],
        'provider' => 'UpdateCheck\\ServiceProvider',
        'tools' => [],
        'autoload' => ['psr-4' => ['UpdateCheck\\' => 'src/']],
    ], JSON_UNESCAPED_SLASHES));

    Http::fake([
        'market.test/api/plugins' => Http::response([
            'data' => [[
                'name' => 'update-check',
                'version' => '2.0.0',
                'description' => 'Update test plugin',
                'author' => 'Tests',
                'downloads' => 20,
                'rating' => 4.2,
                'trust_tier' => 'community',
                'manifest_url' => 'https://market.test/manifests/update-check.json',
                'checksum' => 'xyz',
            ]],
        ], 200),
    ]);

    $updates = app(MarketplaceService::class)->checkUpdates();

    expect($updates)->toHaveCount(1)
        ->and($updates[0]['name'])->toBe('update-check')
        ->and($updates[0]['latest_version'])->toBe('2.0.0');
});

it('publishes plugin metadata to marketplace API', function () {
    File::makeDirectory(base_path('storage/framework/testing/publish-source/src'), 0755, true);
    File::put(base_path('storage/framework/testing/publish-source/plugin.json'), json_encode([
        'name' => 'publish-source',
        'version' => '1.0.0',
        'description' => 'Publish me',
        'author' => 'Tests',
        'permissions' => ['none'],
        'provider' => 'PublishSource\\ServiceProvider',
        'tools' => ['publish_tool'],
        'autoload' => ['psr-4' => ['PublishSource\\' => 'src/']],
    ], JSON_UNESCAPED_SLASHES));

    Http::fake([
        'market.test/api/plugins' => Http::response(['status' => 'ok'], 201),
    ]);

    $result = app(MarketplaceService::class)->publish(base_path('storage/framework/testing/publish-source'));

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://market.test/api/plugins'
            && $request->method() === 'POST'
            && $request['name'] === 'publish-source'
            && $request['tools'] === ['publish_tool'];
    });

    expect($result['status'] ?? null)->toBe('ok');
});

it('supports marketplace search command output', function () {
    Http::fake([
        'market.test/api/plugins' => Http::response([
            'data' => [[
                'name' => 'market-calc',
                'version' => '1.0.0',
                'description' => 'Calculator plugin',
                'author' => 'Tests',
                'downloads' => 200,
                'rating' => 4.4,
                'trust_tier' => 'verified',
                'manifest_url' => 'https://market.test/manifests/market-calc.json',
                'checksum' => 'cmd',
            ]],
        ], 200),
    ]);

    test()->artisan('aegis:marketplace:search calc')
        ->expectsTable(['Name', 'Version', 'Author', 'Trust', 'Downloads'], [[
            'market-calc',
            '1.0.0',
            'Tests',
            'Verified',
            '200',
        ]])
        ->assertExitCode(0);
});
