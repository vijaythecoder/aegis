<?php

use App\Livewire\SecurityDashboard;
use App\Models\CapabilityToken;
use App\Models\Conversation;
use App\Security\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

uses(RefreshDatabase::class);

test('security page is accessible', function () {
    $this->get('/security')->assertStatus(200);
});

test('security page renders livewire component', function () {
    $this->get('/security')->assertSeeLivewire('security-dashboard');
});

test('shows empty state when no audit logs', function () {
    Livewire::test(SecurityDashboard::class)
        ->assertSee('No audit logs found');
});

test('shows empty state when no capability tokens', function () {
    Livewire::test(SecurityDashboard::class)
        ->assertSee('No active capability tokens');
});

test('lists audit logs', function () {
    $conversation = Conversation::factory()->create();

    app(AuditLogger::class)->log(
        action: 'tool_call',
        toolName: 'file_read',
        parameters: ['path' => '/tmp/test.txt'],
        result: 'allowed',
        conversationId: $conversation->id,
    );

    Livewire::test(SecurityDashboard::class)
        ->assertSee('tool_call')
        ->assertSee('file_read');
});

test('can verify audit integrity', function () {
    $conversation = Conversation::factory()->create();
    $logger = app(AuditLogger::class);

    $logger->log('tool_call', 'test', [], 'allowed', $conversation->id);

    Livewire::test(SecurityDashboard::class)
        ->call('verifyIntegrity')
        ->assertSee('entries valid');
});

test('can revoke capability token', function () {
    $token = CapabilityToken::query()->create([
        'capability' => 'execute',
        'scope' => '*',
        'issuer' => 'test',
    ]);

    Livewire::test(SecurityDashboard::class)
        ->assertSee('execute')
        ->call('revokeToken', $token->id)
        ->assertSee('Capability token revoked');

    expect($token->fresh()->revoked)->toBeTrue();
});

test('sidebar has security link', function () {
    $this->get('/chat')
        ->assertSee('Security');
});
