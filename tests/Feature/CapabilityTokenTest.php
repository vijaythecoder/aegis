<?php

use App\Models\CapabilityToken;
use App\Security\PermissionDecision;
use App\Security\PermissionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('capability token model validates non-expired non-revoked tokens', function () {
    $valid = CapabilityToken::query()->create([
        'capability' => 'read_file',
        'scope' => '/tmp/*',
        'issuer' => 'test',
        'expires_at' => now()->addHour(),
    ]);

    $expired = CapabilityToken::query()->create([
        'capability' => 'read_file',
        'scope' => '/tmp/*',
        'issuer' => 'test',
        'expires_at' => now()->subMinute(),
    ]);

    $revoked = CapabilityToken::query()->create([
        'capability' => 'read_file',
        'scope' => '/tmp/*',
        'issuer' => 'test',
        'revoked' => true,
    ]);

    expect($valid->isValid())->toBeTrue()
        ->and($expired->isValid())->toBeFalse()
        ->and($revoked->isValid())->toBeFalse();
});

test('capability token matches capability and scope with fnmatch', function () {
    $token = CapabilityToken::query()->create([
        'capability' => 'read_file',
        'scope' => '/projects/*',
        'issuer' => 'test',
    ]);

    expect($token->matches('read_file', '/projects/foo.txt'))->toBeTrue()
        ->and($token->matches('read_file', '/etc/passwd'))->toBeFalse()
        ->and($token->matches('write_file', '/projects/foo.txt'))->toBeFalse();
});

test('wildcard scope token matches any path', function () {
    $token = CapabilityToken::query()->create([
        'capability' => 'execute',
        'scope' => '*',
        'issuer' => 'test',
    ]);

    expect($token->matches('execute', 'php -v'))->toBeTrue()
        ->and($token->matches('execute', 'ls'))->toBeTrue();
});

test('null scope token matches any scope', function () {
    $token = CapabilityToken::query()->create([
        'capability' => 'read_file',
        'scope' => null,
        'issuer' => 'test',
    ]);

    expect($token->matches('read_file', '/any/path'))->toBeTrue()
        ->and($token->matches('read_file'))->toBeTrue();
});

test('permission manager allows when capability token grants access', function () {
    CapabilityToken::query()->create([
        'capability' => 'execute',
        'scope' => '*',
        'issuer' => 'test',
    ]);

    $manager = app(PermissionManager::class);

    $decision = $manager->check('shell', 'execute', ['command' => 'php -v']);

    expect($decision)->toBe(PermissionDecision::Allowed);
});

test('permission manager grant and revoke capability', function () {
    $manager = app(PermissionManager::class);

    $token = $manager->grantCapability('write_file', '/tmp/*', 'admin');

    expect($token)->toBeInstanceOf(CapabilityToken::class)
        ->and($token->capability)->toBe('write_file')
        ->and($token->scope)->toBe('/tmp/*')
        ->and($token->issuer)->toBe('admin');

    $decision = $manager->check('file_write', 'write', ['scope' => '/tmp/test.txt']);
    expect($decision)->toBe(PermissionDecision::Allowed);

    $manager->revokeCapability($token->id);

    $decision = $manager->check('file_write', 'write', ['scope' => '/tmp/test.txt']);
    expect($decision)->toBe(PermissionDecision::NeedsApproval);
});

test('expired capability tokens are ignored by permission manager', function () {
    CapabilityToken::query()->create([
        'capability' => 'execute',
        'scope' => '*',
        'issuer' => 'test',
        'expires_at' => now()->subMinute(),
    ]);

    $manager = app(PermissionManager::class);
    $decision = $manager->check('shell', 'execute', ['command' => 'php -v']);

    expect($decision)->toBe(PermissionDecision::NeedsApproval);
});

test('active capabilities returns only valid tokens', function () {
    $manager = app(PermissionManager::class);

    $manager->grantCapability('read_file', '*');
    $manager->grantCapability('execute', '*', expiresAt: now()->subMinute());
    $revokable = $manager->grantCapability('write_file', '/tmp/*');
    $manager->revokeCapability($revokable->id);

    $active = $manager->activeCapabilities();

    expect($active)->toHaveCount(1)
        ->and($active->first()->capability)->toBe('read_file');
});
