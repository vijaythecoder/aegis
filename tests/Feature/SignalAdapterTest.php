<?php

use App\Messaging\Adapters\SignalAdapter;
use App\Messaging\AdapterCapabilities;
use App\Messaging\IncomingMessage;

beforeEach(function () {
    config()->set('aegis.messaging.signal.signal_cli_path', 'signal-cli');
    config()->set('aegis.messaging.signal.phone_number', '+15551112222');
});

it('returns signal as adapter name', function () {
    $adapter = new SignalAdapter;
    expect($adapter->getName())->toBe('signal');
});

it('returns correct capabilities for signal', function () {
    $adapter = new SignalAdapter;
    $caps = $adapter->getCapabilities();

    expect($caps)->toBeInstanceOf(AdapterCapabilities::class)
        ->and($caps->supportsMedia)->toBeFalse()
        ->and($caps->supportsButtons)->toBeFalse()
        ->and($caps->supportsMarkdown)->toBeFalse()
        ->and($caps->maxMessageLength)->toBe(4096)
        ->and($caps->supportsEditing)->toBeFalse();
});

it('detects signal-cli availability', function () {
    $adapter = new SignalAdapter(processRunner: function (string $command) {
        if (str_contains($command, '--version')) {
            return ['exitCode' => 0, 'output' => 'signal-cli 0.13.4', 'error' => ''];
        }

        return ['exitCode' => 1, 'output' => '', 'error' => 'not found'];
    });

    expect($adapter->isSignalCliInstalled())->toBeTrue();
});

it('returns false when signal-cli is not installed', function () {
    $adapter = new SignalAdapter(processRunner: function (string $command) {
        return ['exitCode' => 127, 'output' => '', 'error' => 'command not found'];
    });

    expect($adapter->isSignalCliInstalled())->toBeFalse();
});

it('sends message via signal-cli', function () {
    $sentCommand = null;
    $adapter = new SignalAdapter(processRunner: function (string $command) use (&$sentCommand) {
        $sentCommand = $command;

        return ['exitCode' => 0, 'output' => '', 'error' => ''];
    });

    $adapter->sendMessage('+15559876543', 'Hello from Aegis');

    expect($sentCommand)->toContain('signal-cli')
        ->and($sentCommand)->toContain('send')
        ->and($sentCommand)->toContain('+15559876543')
        ->and($sentCommand)->toContain('Hello from Aegis');
});

it('splits long messages before sending via signal-cli', function () {
    $sentCommands = [];
    $adapter = new SignalAdapter(processRunner: function (string $command) use (&$sentCommands) {
        $sentCommands[] = $command;

        return ['exitCode' => 0, 'output' => '', 'error' => ''];
    });

    $longMessage = str_repeat('B', 5000);
    $adapter->sendMessage('+15559876543', $longMessage);

    expect(count($sentCommands))->toBeGreaterThan(1);
});

it('parses signal-cli JSON receive output into IncomingMessage', function () {
    $adapter = new SignalAdapter;

    $jsonOutput = json_encode([
        'envelope' => [
            'source' => '+15559876543',
            'sourceDevice' => 1,
            'timestamp' => 1707820200000,
            'dataMessage' => [
                'message' => 'Hello from Signal!',
                'timestamp' => 1707820200000,
            ],
        ],
    ]);

    $messages = $adapter->parseReceivedMessages($jsonOutput);

    expect($messages)->toHaveCount(1)
        ->and($messages[0])->toBeInstanceOf(IncomingMessage::class)
        ->and($messages[0]->platform)->toBe('signal')
        ->and($messages[0]->senderId)->toBe('+15559876543')
        ->and($messages[0]->content)->toBe('Hello from Signal!');
});

it('handles multiple messages in signal-cli output', function () {
    $adapter = new SignalAdapter;

    $lines = implode("\n", [
        json_encode([
            'envelope' => [
                'source' => '+15551111111',
                'timestamp' => 1707820200000,
                'dataMessage' => ['message' => 'First message', 'timestamp' => 1707820200000],
            ],
        ]),
        json_encode([
            'envelope' => [
                'source' => '+15552222222',
                'timestamp' => 1707820201000,
                'dataMessage' => ['message' => 'Second message', 'timestamp' => 1707820201000],
            ],
        ]),
    ]);

    $messages = $adapter->parseReceivedMessages($lines);

    expect($messages)->toHaveCount(2)
        ->and($messages[0]->content)->toBe('First message')
        ->and($messages[1]->content)->toBe('Second message');
});

it('returns empty array for invalid signal-cli output', function () {
    $adapter = new SignalAdapter;

    expect($adapter->parseReceivedMessages(''))->toBeEmpty()
        ->and($adapter->parseReceivedMessages('not json'))->toBeEmpty();
});

it('skips messages without dataMessage content', function () {
    $adapter = new SignalAdapter;

    $jsonOutput = json_encode([
        'envelope' => [
            'source' => '+15559876543',
            'timestamp' => 1707820200000,
            'receiptMessage' => ['type' => 'DELIVERY'],
        ],
    ]);

    $messages = $adapter->parseReceivedMessages($jsonOutput);
    expect($messages)->toBeEmpty();
});

it('handles incoming message from webhook request', function () {
    $adapter = new SignalAdapter;

    $request = \Illuminate\Http\Request::create('/webhook/signal', 'POST', [], [], [], [], json_encode([
        'envelope' => [
            'source' => '+15559876543',
            'timestamp' => 1707820200000,
            'dataMessage' => [
                'message' => 'Test from Signal',
                'timestamp' => 1707820200000,
            ],
        ],
    ]));
    $request->headers->set('Content-Type', 'application/json');

    $message = $adapter->handleIncomingMessage($request);

    expect($message)->toBeInstanceOf(IncomingMessage::class)
        ->and($message->platform)->toBe('signal')
        ->and($message->content)->toBe('Test from Signal')
        ->and($message->senderId)->toBe('+15559876543');
});

it('builds correct receive command', function () {
    $adapter = new SignalAdapter;
    $command = $adapter->buildReceiveCommand();

    expect($command)->toContain('signal-cli')
        ->and($command)->toContain('receive')
        ->and($command)->toContain('--json');
});

it('builds correct send command', function () {
    $adapter = new SignalAdapter;
    $command = $adapter->buildSendCommand('+15559876543', 'Test message');

    expect($command)->toContain('signal-cli')
        ->and($command)->toContain('send')
        ->and($command)->toContain('-m')
        ->and($command)->toContain('+15559876543');
});
