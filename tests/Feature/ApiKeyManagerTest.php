<?php

use App\Models\Setting;
use App\Security\ApiKeyManager;
use App\Security\ProviderConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('stores and retrieves encrypted api key roundtrip', function () {
    $manager = app(ApiKeyManager::class);
    $plain = 'sk-ant-ABCDEFGHIJKLMNOPQRSTUVWXYZ1234';

    $manager->store('anthropic', $plain);

    $stored = Setting::query()
        ->where('group', 'credentials')
        ->where('key', 'anthropic_api_key')
        ->firstOrFail();

    expect($manager->retrieve('anthropic'))->toBe($plain)
        ->and($stored->is_encrypted)->toBeTrue()
        ->and($stored->value)->not->toBe($plain)
        ->and($stored->value)->not->toContain($plain);
});

it('rejects invalid key formats', function () {
    $manager = app(ApiKeyManager::class);

    expect(fn () => $manager->store('anthropic', 'invalid-key'))
        ->toThrow(InvalidArgumentException::class);

    expect($manager->has('anthropic'))->toBeFalse();
});

it('validates provider key format requirements', function () {
    $config = new ProviderConfig;

    expect($config->validate('anthropic', 'sk-ant-ABCDEFGHIJKLMNOPQRSTUVWXYZ1234'))->toBeTrue()
        ->and($config->validate('openai', 'sk-ABCDEFGHIJKLMNOPQRSTUVWXYZ1234'))->toBeTrue()
        ->and($config->validate('gemini', 'AIABCDEFGHIJKLMNOPQRSTUVWXYZ1234'))->toBeTrue()
        ->and($config->validate('groq', 'gsk_ABCDEFGHIJKLMNOPQRSTUVWXYZ1234'))->toBeTrue()
        ->and($config->validate('deepseek', 'sk-ABCDEFGHIJKLMNOPQRSTUVWXYZ1234'))->toBeTrue()
        ->and($config->validate('openrouter', 'sk-or-ABCDEFGHIJKLMNOPQRSTUVWXYZ1234'))->toBeTrue()
        ->and($config->validate('xai', 'xai-ABCDEFGHIJKLMNOPQRSTUVWXYZ1234'))->toBeTrue()
        ->and($config->validate('mistral', 'sk-ABCDEFGHIJKLMNOPQRSTUVWXYZ1234'))->toBeTrue()
        ->and($config->validate('openai', 'not-a-key'))->toBeFalse()
        ->and($config->requiresKey('ollama'))->toBeFalse();
});

it('lists configured providers with masked status', function () {
    $manager = app(ApiKeyManager::class);

    $manager->store('anthropic', 'sk-ant-ABCDEFGHIJKLMNOPQRSTUVWXYZ1234');

    $list = $manager->list();

    expect($list)->toHaveKey('anthropic')
        ->and($list['anthropic']['is_set'])->toBeTrue()
        ->and($list['anthropic']['masked'])->toBe('sk-...1234')
        ->and($list)->toHaveKey('openai')
        ->and($list['openai']['is_set'])->toBeFalse()
        ->and($list['openai']['masked'])->toBeNull();
});

it('does not store plaintext in raw database value column', function () {
    $manager = app(ApiKeyManager::class);
    $plain = 'sk-ABCDEFGHIJKLMNOPQRSTUVWXYZ1234';

    $manager->store('openai', $plain);

    $raw = Setting::query()
        ->where('group', 'credentials')
        ->where('key', 'openai_api_key')
        ->value('value');

    expect($raw)->not->toBeNull()
        ->and($raw)->not->toBe($plain)
        ->and($raw)->not->toContain('sk-ABCDEFGHIJKLMNOPQRSTUVWXYZ1234');
});

it('supports key management artisan commands', function () {
    test()->artisan('aegis:key:set anthropic --key=sk-ant-ABCDEFGHIJKLMNOPQRSTUVWXYZ1234')
        ->expectsOutputToContain('stored')
        ->assertExitCode(0);

    test()->artisan('aegis:key:test anthropic')
        ->expectsOutputToContain('valid')
        ->assertExitCode(0);

    test()->artisan('aegis:key:list')
        ->expectsOutputToContain('anthropic')
        ->expectsOutputToContain('set')
        ->assertExitCode(0);
});
