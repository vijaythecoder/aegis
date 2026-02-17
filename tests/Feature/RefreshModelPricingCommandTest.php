<?php

use Illuminate\Support\Facades\Http;

test('refresh pricing command succeeds with valid API response', function () {
    Http::fake([
        'models.dev/api.json' => Http::response([
            'anthropic' => [
                'id' => 'anthropic',
                'name' => 'Anthropic',
                'models' => [
                    'claude-sonnet-4-20250514' => [
                        'cost' => ['input' => 3.00, 'output' => 15.00],
                    ],
                ],
            ],
        ], 200),
    ]);

    $this->artisan('aegis:refresh-pricing')
        ->expectsOutput('Fetching pricing data from models.dev...')
        ->expectsOutput('Pricing data refreshed successfully.')
        ->assertExitCode(0);
});

test('refresh pricing command fails on API error', function () {
    Http::fake([
        'models.dev/api.json' => Http::response('Internal Server Error', 500),
    ]);

    $this->artisan('aegis:refresh-pricing')
        ->expectsOutput('Fetching pricing data from models.dev...')
        ->expectsOutput('Failed to refresh pricing data. Check network connectivity.')
        ->assertExitCode(1);
});
