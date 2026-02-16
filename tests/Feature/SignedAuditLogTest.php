<?php

use App\Security\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('adds HMAC signature to new audit log entries', function () {
    $logger = app(AuditLogger::class);
    $log = $logger->log('tool_call', 'file_read', ['path' => '/test'], 'allowed');

    expect($log->signature)->not->toBeNull()
        ->and($log->signature)->toBeString()
        ->and(strlen($log->signature))->toBe(64);
});

it('chains signatures using previous log signature', function () {
    $logger = app(AuditLogger::class);

    $log1 = $logger->log('tool_call', 'file_read', ['path' => '/a'], 'allowed');
    $log2 = $logger->log('tool_call', 'file_write', ['path' => '/b'], 'allowed');

    expect($log2->previous_signature)->toBe($log1->signature)
        ->and($log2->signature)->not->toBe($log1->signature);
});

it('verifies a valid log entry signature', function () {
    $logger = app(AuditLogger::class);
    $log = $logger->log('tool_call', 'shell', ['command' => 'ls'], 'allowed');

    expect($logger->verify($log))->toBeTrue();
});

it('detects tampered log entries', function () {
    $logger = app(AuditLogger::class);
    $log = $logger->log('tool_call', 'shell', ['command' => 'ls'], 'allowed');

    $log->action = 'tampered_action';
    $log->saveQuietly();

    expect($logger->verify($log->fresh()))->toBeFalse();
});

it('verifies entire audit chain integrity', function () {
    $logger = app(AuditLogger::class);

    $logger->log('tool_call', 'file_read', ['path' => '/a'], 'allowed');
    $logger->log('tool_call', 'file_write', ['path' => '/b'], 'allowed');
    $logger->log('tool_call', 'shell', ['command' => 'ls'], 'allowed');

    $result = $logger->verifyChain();

    expect($result['valid'])->toBeTrue()
        ->and($result['total'])->toBe(3)
        ->and($result['verified'])->toBe(3);
});

it('detects broken chain from tampered entry', function () {
    $logger = app(AuditLogger::class);

    $logger->log('tool_call', 'file_read', ['path' => '/a'], 'allowed');
    $log2 = $logger->log('tool_call', 'file_write', ['path' => '/b'], 'allowed');
    $logger->log('tool_call', 'shell', ['command' => 'ls'], 'allowed');

    $log2->action = 'tampered';
    $log2->saveQuietly();

    $result = $logger->verifyChain();

    expect($result['valid'])->toBeFalse()
        ->and($result['first_failure'])->toBe($log2->id);
});
