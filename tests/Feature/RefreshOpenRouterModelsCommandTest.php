<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

beforeEach(function () {
    Cache::forget('openrouter_available_models');
});

test('refresh-models command succeeds with valid API response', function () {
    Http::fake([
        'openrouter.ai/api/v1/models' => Http::response(['data' => [
            [
                'id' => 'anthropic/claude-sonnet-4',
                'name' => 'Claude Sonnet 4',
                'context_length' => 200000,
                'pricing' => ['prompt' => '0.000003', 'completion' => '0.000015'],
                'architecture' => ['input_modalities' => ['text']],
                'supported_parameters' => ['tools'],
            ],
        ]], 200),
    ]);

    $this->artisan('aegis:refresh-models')
        ->expectsOutputToContain('Models refreshed successfully')
        ->assertExitCode(0);
});

test('refresh-models command fails gracefully on API error', function () {
    Http::fake([
        'openrouter.ai/api/v1/models' => Http::response('Server Error', 500),
    ]);

    $this->artisan('aegis:refresh-models')
        ->expectsOutputToContain('Failed to refresh models')
        ->assertExitCode(1);
});
