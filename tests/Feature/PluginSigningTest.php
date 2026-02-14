<?php

use App\Plugins\PluginInstaller;
use App\Plugins\PluginManager;
use App\Plugins\PluginSigner;
use App\Plugins\PluginVerifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

uses(RefreshDatabase::class);

beforeEach(function () {
    $sandbox = pluginSigningSandboxPath();

    File::deleteDirectory($sandbox);
    File::makeDirectory($sandbox.'/sources', 0755, true);
    File::makeDirectory($sandbox.'/installed', 0755, true);

    config([
        'aegis.plugins.path' => $sandbox.'/installed',
        'aegis.plugins.signing.secret_key_path' => $sandbox.'/keys/ed25519.secret',
        'aegis.plugins.signing.public_key_path' => $sandbox.'/keys/ed25519.public',
    ]);

    app()->forgetInstance(PluginManager::class);
    app()->forgetInstance(PluginInstaller::class);
    app()->forgetInstance(PluginSigner::class);
    app()->forgetInstance(PluginVerifier::class);
});

afterEach(function () {
    File::deleteDirectory(pluginSigningSandboxPath());
});

it('verifies a valid signed plugin as verified by aegis', function () {
    $pluginPath = createSigningPluginFixture(pluginSigningSandboxPath().'/sources', 'signed-valid');

    $signer = app(PluginSigner::class);
    $signer->writeDefaultKeyPair();
    $signer->signPath($pluginPath);

    $result = app(PluginVerifier::class)->verifyPath($pluginPath);

    expect($result['status'])->toBe(PluginVerifier::STATUS_VALID)
        ->and($result['trust_level'])->toBe(PluginVerifier::TRUST_VERIFIED_BY_AEGIS)
        ->and($result['valid'])->toBeTrue()
        ->and($result['tampered'])->toBeFalse();
});

it('fails verification when a signed plugin is modified', function () {
    $pluginPath = createSigningPluginFixture(pluginSigningSandboxPath().'/sources', 'signed-tampered');

    $signer = app(PluginSigner::class);
    $signer->writeDefaultKeyPair();
    $signer->signPath($pluginPath);

    File::append($pluginPath.'/src/Tool.php', "\n<?php\n// tampered\n");

    $result = app(PluginVerifier::class)->verifyPath($pluginPath);

    expect($result['status'])->toBe(PluginVerifier::STATUS_TAMPERED)
        ->and($result['trust_level'])->toBe(PluginVerifier::TRUST_VERIFIED_BY_AEGIS)
        ->and($result['valid'])->toBeFalse()
        ->and($result['tampered'])->toBeTrue();
});

it('returns unsigned trust level when signature metadata is missing', function () {
    $pluginPath = createSigningPluginFixture(pluginSigningSandboxPath().'/sources', 'unsigned-plugin');

    $result = app(PluginVerifier::class)->verifyPath($pluginPath);

    expect($result['status'])->toBe(PluginVerifier::STATUS_UNSIGNED)
        ->and($result['trust_level'])->toBe(PluginVerifier::TRUST_UNSIGNED)
        ->and($result['valid'])->toBeTrue()
        ->and($result['tampered'])->toBeFalse();
});

it('classifies non-aegis valid signatures as author signed', function () {
    $pluginPath = createSigningPluginFixture(pluginSigningSandboxPath().'/sources', 'author-signed');

    $signer = app(PluginSigner::class);
    $signer->writeDefaultKeyPair();

    $authorPair = $signer->generateKeyPair();
    $authorSecretPath = pluginSigningSandboxPath().'/keys/author.secret';
    File::put($authorSecretPath, $authorPair['secret_key']);

    config([
        'aegis.plugins.signing.secret_key_path' => $authorSecretPath,
    ]);

    app()->forgetInstance(PluginSigner::class);
    app(PluginSigner::class)->signPath($pluginPath);

    $result = app(PluginVerifier::class)->verifyPath($pluginPath);

    expect($result['status'])->toBe(PluginVerifier::STATUS_VALID)
        ->and($result['trust_level'])->toBe(PluginVerifier::TRUST_AUTHOR_SIGNED)
        ->and($result['valid'])->toBeTrue()
        ->and($result['tampered'])->toBeFalse();
});

it('blocks install when a signed plugin is tampered', function () {
    $pluginPath = createSigningPluginFixture(pluginSigningSandboxPath().'/sources', 'install-blocked');

    $signer = app(PluginSigner::class);
    $signer->writeDefaultKeyPair();
    $signer->signPath($pluginPath);

    File::append($pluginPath.'/src/Tool.php', "\n<?php\n// changed\n");

    expect(fn () => app(PluginInstaller::class)->install($pluginPath))
        ->toThrow(InvalidArgumentException::class, 'failed signature verification');
});

it('allows unsigned plugins to install with unsigned trust status', function () {
    $pluginPath = createSigningPluginFixture(pluginSigningSandboxPath().'/sources', 'install-unsigned');

    $installer = app(PluginInstaller::class);
    $manifest = $installer->install($pluginPath);

    expect($manifest->name)->toBe('install-unsigned')
        ->and(File::isDirectory(config('aegis.plugins.path').'/install-unsigned'))->toBeTrue()
        ->and($installer->lastVerification()['status'])->toBe(PluginVerifier::STATUS_UNSIGNED)
        ->and($installer->lastVerification()['trust_level'])->toBe(PluginVerifier::TRUST_UNSIGNED);
});

it('supports artisan keygen sign and verify command flow', function () {
    $pluginPath = createSigningPluginFixture(pluginSigningSandboxPath().'/sources', 'command-flow');

    test()->artisan('aegis:plugin:keygen')
        ->expectsOutputToContain('Generated plugin signing keypair')
        ->assertExitCode(0);

    test()->artisan('aegis:plugin:sign '.$pluginPath)
        ->expectsOutputToContain('Plugin signed successfully')
        ->assertExitCode(0);

    test()->artisan('aegis:plugin:verify '.$pluginPath)
        ->expectsOutputToContain('Trust level: verified_by_aegis')
        ->assertExitCode(0);
});

function createSigningPluginFixture(string $root, string $name): string
{
    $pluginPath = rtrim($root, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$name;

    File::makeDirectory($pluginPath.'/src', 0755, true);

    $namespace = str_replace(' ', '', ucwords(str_replace(['-', '_'], ' ', $name)));
    $manifest = [
        'name' => $name,
        'version' => '1.0.0',
        'description' => "Plugin {$name}",
        'author' => 'Tests',
        'permissions' => ['none'],
        'provider' => $namespace.'\\'.$namespace.'ServiceProvider',
        'tools' => [],
        'autoload' => [
            'psr-4' => [
                $namespace.'\\' => 'src/',
            ],
        ],
    ];

    File::put($pluginPath.'/plugin.json', (string) json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    File::put($pluginPath.'/src/Tool.php', "<?php\n\nnamespace {$namespace};\n\nclass Tool\n{\n    public function run(): string\n    {\n        return 'ok';\n    }\n}\n");

    return $pluginPath;
}

function pluginSigningSandboxPath(): string
{
    return base_path('storage/framework/testing/plugin-signing');
}
