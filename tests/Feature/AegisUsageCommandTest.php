<?php

use App\Models\Conversation;
use App\Models\TokenUsage;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

test('aegis:usage command runs successfully', function () {
    $this->artisan('aegis:usage')
        ->assertExitCode(0);
});

test('aegis:usage shows summary table', function () {
    $conversation = Conversation::factory()->create();

    TokenUsage::factory()->create([
        'conversation_id' => $conversation->id,
        'provider' => 'anthropic',
        'model' => 'claude-sonnet-4-20250514',
        'estimated_cost' => 0.015,
        'total_tokens' => 2500,
    ]);

    $this->artisan('aegis:usage --period=all')
        ->assertExitCode(0)
        ->expectsOutputToContain('anthropic');
});

test('aegis:usage accepts period option', function () {
    $this->artisan('aegis:usage --period=30d')
        ->assertExitCode(0);
});

test('aegis:usage shows empty state gracefully', function () {
    $this->artisan('aegis:usage --period=all')
        ->assertExitCode(0)
        ->expectsOutputToContain('Token Usage Summary');
});
