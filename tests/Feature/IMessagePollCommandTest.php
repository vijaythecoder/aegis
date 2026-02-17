<?php

use Symfony\Component\Console\Command\Command as CommandStatus;

it('fails on non-macOS platforms', function () {
    if (PHP_OS === 'Darwin') {
        $this->markTestSkipped('Test only runs on non-macOS platforms.');
    }

    $this->artisan('aegis:imessage:poll')
        ->expectsOutput('iMessage polling is only available on macOS.')
        ->assertExitCode(CommandStatus::FAILURE);
});

it('fails when imessage is disabled', function () {
    if (PHP_OS !== 'Darwin') {
        $this->markTestSkipped('Test only runs on macOS.');
    }

    config(['aegis.messaging.imessage.enabled' => false]);

    $this->artisan('aegis:imessage:poll')
        ->expectsOutput('iMessage integration is disabled. Enable it in Settings > Messaging.')
        ->assertExitCode(CommandStatus::FAILURE);
});

it('has correct command signature', function () {
    $this->artisan('aegis:imessage:poll --help')
        ->assertSuccessful();
});

it('accepts interval option', function () {
    $this->artisan('aegis:imessage:poll --help')
        ->expectsOutputToContain('interval')
        ->assertSuccessful();
});
