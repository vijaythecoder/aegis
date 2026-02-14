<?php

use App\Mobile\MobilePairingService;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    Setting::query()->updateOrCreate(
        ['group' => 'app', 'key' => 'onboarding_completed'],
        ['value' => '1']
    );
});

it('generates pairing token with QR data', function () {
    $service = app(MobilePairingService::class);
    $pairing = $service->generatePairing('192.168.1.100', 8001);

    expect($pairing)->toHaveKeys(['token', 'qr_data', 'expires_at'])
        ->and($pairing['token'])->toBeString()->not->toBeEmpty()
        ->and($pairing['qr_data'])->toContain('192.168.1.100')
        ->and($pairing['qr_data'])->toContain('8001');
});

it('validates pairing token successfully', function () {
    $service = app(MobilePairingService::class);
    $pairing = $service->generatePairing('192.168.1.100', 8001);

    $result = $service->validatePairing($pairing['token']);

    expect($result)->toBeTrue();
});

it('rejects invalid pairing token', function () {
    $service = app(MobilePairingService::class);

    $result = $service->validatePairing('invalid-token-12345');

    expect($result)->toBeFalse();
});

it('rejects expired pairing token', function () {
    $service = app(MobilePairingService::class);
    $pairing = $service->generatePairing('192.168.1.100', 8001, ttlMinutes: 0);

    sleep(1);
    $result = $service->validatePairing($pairing['token']);

    expect($result)->toBeFalse();
});

it('pairs mobile device via API endpoint', function () {
    $service = app(MobilePairingService::class);
    $pairing = $service->generatePairing('192.168.1.100', 8001);

    $response = $this->postJson('/api/mobile/pair', [
        'token' => $pairing['token'],
        'device_name' => 'iPhone 15',
    ]);

    $response->assertOk()
        ->assertJsonStructure(['session_token', 'device_id']);
});

it('rejects pairing with invalid token via API', function () {
    $response = $this->postJson('/api/mobile/pair', [
        'token' => 'bad-token',
        'device_name' => 'iPhone 15',
    ]);

    $response->assertStatus(401);
});

it('sends chat message via mobile API', function () {
    $service = app(MobilePairingService::class);
    $pairing = $service->generatePairing('127.0.0.1', 8001);
    $pairResponse = $this->postJson('/api/mobile/pair', [
        'token' => $pairing['token'],
        'device_name' => 'Test Device',
    ]);

    $sessionToken = $pairResponse->json('session_token');

    $response = $this->postJson('/api/mobile/chat', [
        'message' => 'Hello from mobile',
    ], ['Authorization' => 'Bearer ' . $sessionToken]);

    $response->assertOk()
        ->assertJsonStructure(['conversation_id', 'response']);
});

it('rejects chat without valid session token', function () {
    $response = $this->postJson('/api/mobile/chat', [
        'message' => 'Hello',
    ], ['Authorization' => 'Bearer invalid-token']);

    $response->assertStatus(401);
});

it('lists conversations via mobile API', function () {
    $service = app(MobilePairingService::class);
    $pairing = $service->generatePairing('127.0.0.1', 8001);
    $pairResponse = $this->postJson('/api/mobile/pair', [
        'token' => $pairing['token'],
        'device_name' => 'Test Device',
    ]);
    $sessionToken = $pairResponse->json('session_token');

    $conversation = Conversation::create([
        'title' => 'Test Conversation',
        'model' => 'claude-sonnet-4-20250514',
        'provider' => 'anthropic',
        'status' => 'active',
    ]);

    $response = $this->getJson('/api/mobile/conversations', [
        'Authorization' => 'Bearer ' . $sessionToken,
    ]);

    $response->assertOk()
        ->assertJsonStructure(['conversations']);
});

it('returns conversation messages via mobile API', function () {
    $service = app(MobilePairingService::class);
    $pairing = $service->generatePairing('127.0.0.1', 8001);
    $pairResponse = $this->postJson('/api/mobile/pair', [
        'token' => $pairing['token'],
        'device_name' => 'Test Device',
    ]);
    $sessionToken = $pairResponse->json('session_token');

    $conversation = Conversation::create([
        'title' => 'Test',
        'model' => 'claude-sonnet-4-20250514',
        'provider' => 'anthropic',
        'status' => 'active',
    ]);

    Message::create([
        'conversation_id' => $conversation->id,
        'role' => 'user',
        'content' => 'Hello',
        'token_count' => 1,
    ]);

    $response = $this->getJson("/api/mobile/conversations/{$conversation->id}/messages", [
        'Authorization' => 'Bearer ' . $sessionToken,
    ]);

    $response->assertOk()
        ->assertJsonStructure(['messages']);
});

it('returns mobile status endpoint', function () {
    $response = $this->getJson('/api/mobile/status');

    $response->assertOk()
        ->assertJsonStructure(['version', 'name', 'mobile_api']);
});

it('generates QR data in correct format', function () {
    $service = app(MobilePairingService::class);
    $pairing = $service->generatePairing('10.0.0.5', 8001);

    $qrData = json_decode($pairing['qr_data'], true);

    expect($qrData)->toHaveKeys(['host', 'port', 'token'])
        ->and($qrData['host'])->toBe('10.0.0.5')
        ->and($qrData['port'])->toBe(8001)
        ->and($qrData['token'])->toBe($pairing['token']);
});

it('returns responsive chat page for mobile viewport', function () {
    $response = $this->get('/mobile/chat');

    $response->assertOk()
        ->assertSee('Aegis')
        ->assertSee('viewport');
});
